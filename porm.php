<?php


declare(strict_types=1);


spl_autoload_register(function(string $class) {
    if (substr($class, 0, 5) === 'PORM\\') {
        require_once __DIR__ . '/src/' . strtr(substr($class, 5), ['\\' => '/']) . '.php';
    }
});


class porm {

    private static $factory;

    private static $config = [];

    private static $cacheDir = null;


    public static function init(array $config, ?string $cacheDir = null) : void {
        if (isset(self::$factory)) {
            throw new RuntimeException("PORM has already been initialised");
        }

        self::$config = $config;
        self::$cacheDir = $cacheDir;
    }

    public static function getManager(string $entity) {
        return self::getFactory()->getManager($entity);
    }

    public static function getEventDispatcher() : PORM\EventDispatcher {
        return self::getFactory()->getEventDispatcher();
    }


    public static function getFactory() : PORM\Factory {
        return self::$factory ?? self::$factory = new PORM\Factory(self::$config, self::$cacheDir);
    }

}
