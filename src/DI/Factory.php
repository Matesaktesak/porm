<?php

declare(strict_types=1);

namespace PORM\DI;

use PORM\Connection;
use PORM\Drivers;
use PORM\EventDispatcher;
use PORM\EntityManager;
use PORM\Metadata;
use PORM\Mapper;
use PORM\SQL;
use PORM\Migrations;
use PORM\Bridges;
use PORM\Cache;
use PORM\Command;
use Tracy\IBarPanel;


class Factory {

    const DEFAULTS = [
        'connection' => [
            'platform' => null,
        ],
        'entities' => [],
        'namingStrategy' => 'snakeCase',
        'migrationsDir' => null,
        'debugger' => null,
    ];


    /** @var array */
    private array $config;

    /** @var string */
    private ?string $cacheDir;

    /** @var callable */
    private $cacheStorageFactory;



    public function __construct(array $config, ?string $cacheDir = null) {
        $this->config = array_replace_recursive(self::DEFAULTS, $config);
        $this->cacheDir = $cacheDir;

        if ($this->config['debugger'] === null) {
            $this->config['debugger'] = class_exists(IBarPanel::class);
        }
    }


    public function createConnection(Drivers\IDriver $driver, Drivers\IPlatform $platform) : Connection {
        return new Connection($driver, $platform);
    }

    public function createDriver() : Drivers\IDriver {
        $options = $this->config['connection'];

        if (empty($options['driver'])) {
            $options['driver'] = $options['platform'];
        }

        if (is_string($options['driver'])) {
            $driver = $options['driver'];
            unset($options['driver'], $options['platform']);

            if (!class_exists($driver) && class_exists($tmp = 'PORM\\Drivers\\' . ucfirst($driver) . '\\Driver')) {
                $driver = $tmp;
            }

            $driver = new $driver($options);
        } else {
            $driver = $options['driver'];
        }

        if ($driver instanceof Drivers\IDriver) {
            return $driver;
        } else {
            throw new \RuntimeException("Invalid driver option, expected a string or an instance of " . Drivers\IDriver::class);
        }
    }

    public function createPlatform() : Drivers\IPlatform {
        $options = $this->config['connection'];

        if (is_string($options['platform'])) {
            $platform = $options['platform'];

            if (!class_exists($platform) && class_exists($tmp = 'PORM\\Drivers\\' . ucfirst($platform) . '\\Platform')) {
                $platform = $tmp;
            }

            $platform = new $platform();
        } else {
            $platform = $options['platform'];
        }

        if ($platform instanceof Drivers\IPlatform) {
            return $platform;
        } else {
            throw new \RuntimeException("Invalid platform option, expected a string or an instance of " . Drivers\IPlatform::class);
        }
    }

    public function createEventDispatcher() : EventDispatcher {
        return new EventDispatcher();
    }

    public function createEntityManager(
        Connection $connection,
        Mapper $mapper,
        Metadata\Provider $provider,
        SQL\Translator $translator,
        SQL\AST\Builder $astBuilder,
        EventDispatcher $eventDispatcher
    ) : EntityManager {
        return new EntityManager(
            $connection,
            $mapper,
            $provider,
            $translator,
            $astBuilder,
            $eventDispatcher
        );
    }

    public function createManager(Metadata\Provider $provider, EntityManager $entityManager, string $entity) {
        $meta = $provider->get($entity);
        $class = $meta->getManagerClass();
        return new $class($entityManager, $meta);
    }

    public function createMetadataCompiler(Metadata\INamingStrategy $namingStrategy) : Metadata\Compiler {
        return new Metadata\Compiler($namingStrategy);
    }

    public function createMetadataProvider(Metadata\Compiler $compiler, ?Cache\IStorage $cacheStorage = null) : Metadata\Provider {
        return new Metadata\Provider($compiler, $cacheStorage, $this->config['entities']);
    }

    public function createNamingStrategy() : Metadata\INamingStrategy {
        $strategy = $this->config['namingStrategy'];

        if (is_string($strategy)) {
            if (!class_exists($strategy) && class_exists($tmp = 'PORM\\Metadata\\NamingStrategy\\' . ucfirst($strategy))) {
                $strategy = $tmp;
            }

            $strategy = new $strategy();
        }

        if ($strategy instanceof Metadata\INamingStrategy) {
            return $strategy;
        } else {
            throw new \RuntimeException("Invalid naming strategy, expected a string or an instance of " . Metadata\INamingStrategy::class);
        }
    }

    public function createMapper(Drivers\IPlatform $platform) : Mapper {
        return new Mapper($platform);
    }

    public function createTranslator(
        SQL\AST\Parser $parser,
        Metadata\Provider $provider,
        Drivers\IPlatform $platform,
        EventDispatcher $eventDispatcher,
        ?Cache\IStorage $cacheStorage = null
    ) : SQL\Translator {
        return new SQL\Translator(
            $parser,
            $provider,
            $platform,
            $eventDispatcher,
            $cacheStorage
        );
    }

    public function createASTParser() : SQL\AST\Parser {
        return new SQL\AST\Parser();
    }

    public function createASTBuilder(Metadata\Provider $provider, SQL\AST\Parser $parser, Drivers\IPlatform $platform) : SQL\AST\Builder {
        return new SQL\AST\Builder($provider, $parser, $platform);
    }

    public function createMigrationResolver(Drivers\IDriver $driver, Drivers\IPlatform $platform) : Migrations\Resolver {
        return new Migrations\Resolver($driver, $platform, $this->config['migrationsDir']);
    }

    public function createMigrationRunner(Drivers\IDriver $driver) : Migrations\Runner {
        return new Migrations\Runner($driver);
    }

    public function createRunMigrationsCommand(Migrations\Resolver $resolver, Migrations\Runner $runner) : Command\RunMigrationsCommand {
        return new Command\RunMigrationsCommand($resolver, $runner);
    }

    public function createTracyBarPanel() : Bridges\Tracy\BarPanel {
        return new Bridges\Tracy\BarPanel();
    }

    public function createTracyDebuggerPanel() : Bridges\Tracy\DebuggerPanel {
        return new Bridges\Tracy\DebuggerPanel();
    }

    public function createCacheStorage(string $namespace) : ?Cache\IStorage {
        return call_user_func_array($this->getCacheStorageFactory(), func_get_args());
    }

    public function setCacheStorageFactory(callable $factory) : void {
        $this->cacheStorageFactory = $factory;
    }

    private function getCacheStorageFactory() : callable {
        if (!isset($this->cacheStorageFactory)) {
            $this->cacheStorageFactory = \Closure::fromCallable([$this, 'createDefaultCacheStorage']);
        }

        return $this->cacheStorageFactory;
    }

    private function createDefaultCacheStorage(string $namespace, ?callable $serializer = null) : ?Cache\IStorage {
        return $this->cacheDir ? new Cache\Storage\PhpStorage($this->cacheDir, $namespace, $serializer) : null;
    }

}
