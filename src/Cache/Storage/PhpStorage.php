<?php

declare(strict_types=1);

namespace PORM\Cache\Storage;

use PORM\Cache\IStorage;


class PhpStorage implements IStorage {

    private string $cacheDir;

    private $serializer;

    private bool $initialized = false;


    public function __construct(string $cacheDir, string $namespace, ?callable $serializer = null) {
        $this->cacheDir = $cacheDir . '/' . $namespace;
        $this->serializer = $serializer;
    }

    public function get(string $key, callable $generator) {
        $this->init();
        $path = $this->cacheDir . '/' . $key . '.php';
        $lock = $this->cacheDir . '/' . $key . '.lock';

        $fp = fopen($path, 'c+'); // actual cache file & write lock target
        $lp = fopen($lock, 'c+'); // auxiliary read lock target

        do {
            flock($fp, LOCK_SH); // get write lock - THIS makes readers wait for writers to finish
            flock($lp, LOCK_SH); // get read lock
            flock($fp, LOCK_UN); // release write lock so that writers don't get starved

            if (fgetc($fp) === false) { // entry is empty or didn't exist before
                flock($lp, LOCK_UN); // release own reader's lock
                flock($fp, LOCK_EX); // get write lock i.e. wait for readers to get a read lock and writers to do whatever

                if (fgetc($fp) !== false) { // if the last flock() actually waited for another writer to finish,
                    continue;               // the cache entry got repopulated while we waited, so just read it like usual
                }

                flock($lp, LOCK_EX); // THIS makes writers wait for all readers to finish
                flock($lp, LOCK_UN); // but now we don't need it anymore because we have the write lock -
                fclose($lp);         //  - no new readers or writers will appear at this point

                try {
                    $value = call_user_func($generator);

                    if ($this->serializer) {
                        $serialized = call_user_func($this->serializer, $value);

                        if (!$serialized || !is_string($serialized)) {
                            $serialized = '<?php return null;';
                        }
                    } else {
                        $serialized = '<?php return unserialize(' . var_export(serialize($value), true) . ');';
                    }

                    ftruncate($fp, 0);
                    fwrite($fp, $serialized);

                    if (function_exists('opcache_get_status') && opcache_is_script_cached($path)) {
                        opcache_invalidate($path, true);
                    }

                    return $value;

                } finally {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                }
            } else {
                fclose($fp);

                try {
                    return require $path;
                } finally {
                    flock($lp, LOCK_UN);
                    fclose($lp);
                }
            }
        } while (true);
    }


    private function init() {
        if (!$this->initialized) {
            $this->initialized = true;
            @mkdir($this->cacheDir, 0755, true);
        }
    }

}
