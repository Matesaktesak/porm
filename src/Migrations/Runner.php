<?php

declare(strict_types=1);

namespace PORM\Migrations;

use PORM\Drivers\IDriver;
use PORM\Exceptions\MigrationException;


class Runner {

    private IDriver $driver;


    public function __construct(IDriver $driver) {
        $this->driver = $driver;
    }


    public function run(string $path) : void {
        if (substr($path, -4) === '.sql') {
            $this->runSqlMigration($path);
        } else {
            $this->runPhpMigration($path);
        }
    }


    private function runSqlMigration(string $path) : void {
        $n = 1;

        foreach ($this->loadAndParseFile($path) as $stmt) {
            try {
                $this->driver->query($stmt);
                $n++;
            } catch (\Throwable $e) {
                throw new MigrationException("An exception occurred when executing statement #$n", 0, $e);
            }
        }
    }

    private function runPhpMigration(string $path) : void {
        require $path;

        $class = basename($path, '.php');

        if (!class_exists($class) || !(new \ReflectionClass($class))->implementsInterface(IMigration::class)) {
            throw new MigrationException("Invalid migration '$path': doesn't declare the '$class' class or the class doesn't implement the " . IMigration::class . ' interface');
        }

        /** @var IMigration $migration */
        $migration = new $class();

        try {
            $migration->run($this->driver);
        } catch (\Throwable $e) {
            throw new MigrationException("An exception occurred when executing migration", 0, $e);
        }
    }



    private function loadAndParseFile(string $path) : \Generator {
        $query = file_get_contents($path);

        $space = "(?:\\s|/\\*[\s\S]*?\\*/|(?:#|-- )[^\n]*\n?|--\r?\n)";
        $delimiter = ";";
        $offset = 0;
        $parse = '[\'"]|/\\*|-- |$';

        while ($query != '') {
            if (!$offset && preg_match("~^$space*+SET\\s+TERM\\s+(\\S+)\\s*" . preg_quote($delimiter) . "~i", $query, $match)) {
                $delimiter = $match[1];
                $query = substr($query, strlen($match[0]));
            } else {
                preg_match('(' . preg_quote($delimiter) . "\\s*|$parse)", $query, $match, PREG_OFFSET_CAPTURE, $offset); // should always match
                list($found, $pos) = $match[0];
                $pos = (int)$pos;

                if (!$found && rtrim($query) == "") {
                    break;
                }

                $offset = $pos + strlen($found);

                if ($found && rtrim($found) != $delimiter) { // find matching quote or comment end
                    while (preg_match('(' . ($found == '/*' ? '\\*/' : ($found == '[' ? ']' : (preg_match('~^-- |^#~', $found) ? "\n" : preg_quote($found) . "|\\\\."))) . '|$)s', $query, $match, PREG_OFFSET_CAPTURE, $offset)) { //! respect sql_mode NO_BACKSLASH_ESCAPES
                        $s = $match[0][0];
                        $offset = (int)$match[0][1] + strlen($s);

                        if ($s[0] != "\\") {
                            break;
                        }
                    }
                } else {
                    yield trim(substr($query, 0, $pos));
                    $query = substr($query, $offset);
                    $offset = 0;
                }
            }
        }
    }

}
