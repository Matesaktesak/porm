<?php

declare(strict_types=1);

namespace PORM;

use Tracy\IBarPanel;


class Factory {

    const DEFAULTS = [
        'connection' => [
            'platform' => null,
        ],
        'entities' => [],
        'namingStrategy' => null,
        'migrationsDir' => null,
        'debugger' => null,
    ];


    /** @var array */
    private $config;

    /** @var string */
    private $cacheDir;

    /** @var Connection */
    private $connection;

    /** @var Drivers\IDriver */
    private $driver;

    /** @var Drivers\IPlatform */
    private $platform;

    /** @var EventDispatcher */
    private $eventDispatcher;

    /** @var EntityManager */
    private $entityManager;

    /** @var object[] */
    private $managers = [];

    /** @var Metadata\Provider */
    private $metadataProvider;

    /** @var Metadata\Registry */
    private $metadataRegistry;

    /** @var Metadata\INamingStrategy */
    private $namingStrategy;

    /** @var Mapper */
    private $mapper;

    /** @var SQL\Translator */
    private $translator;

    /** @var Migrations\Resolver */
    private $migrationResolver;

    /** @var Migrations\Runner */
    private $migrationRunner;

    /** @var SQL\AST\Parser */
    private $astParser;

    /** @var SQL\AST\Builder */
    private $astBuilder;

    /** @var Bridges\Tracy\Panel */
    private $tracyPanel;

    /** @var callable */
    private $cacheStorageFactory;


    public function __construct(array $config, ?string $cacheDir = null) {
        $this->config = array_replace_recursive(self::DEFAULTS, $config);
        $this->cacheDir = $cacheDir;

        if ($this->config['debugger'] === null) {
            $this->config['debugger'] = class_exists(IBarPanel::class);
        }
    }


    public function getConnection() : Connection {
        if (!$this->connection) {
            $this->connection = new Connection($this->getDriver(), $this->getPlatform());

            if ($this->config['debugger']) {
                $this->connection->addListener([$this->getTracyPanel(), 'logEvent']);
            }
        }

        return $this->connection;
    }


    public function getDriver() : Drivers\IDriver {
        if ($this->driver) {
            return $this->driver;
        }

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
            return $this->driver = $driver;
        } else {
            throw new \RuntimeException("Invalid driver option, expected a string or an instance of " . Drivers\IDriver::class);
        }
    }

    public function getPlatform() : Drivers\IPlatform {
        if ($this->platform) {
            return $this->platform;
        }

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
            return $this->platform = $platform;
        } else {
            throw new \RuntimeException("Invalid platform option, expected a string or an instance of " . Drivers\IPlatform::class);
        }
    }

    public function getEventDispatcher() : EventDispatcher {
        return $this->eventDispatcher ?? $this->eventDispatcher = new EventDispatcher();
    }

    public function getEntityManager() : EntityManager {
        return $this->entityManager ?? $this->entityManager = new EntityManager(
            $this->getConnection(),
            $this->getMapper(),
            $this->getMetadataRegistry(),
            $this->getTranslator(),
            $this->getASTBuilder(),
            $this->getEventDispatcher()
        );
    }


    public function getManager(string $entity) {
        $entity = $this->getMetadataRegistry()->normalizeEntityClass($entity);

        if (isset($this->managers[$entity])) {
            return $this->managers[$entity];
        }

        $meta = $this->getMetadataRegistry()->get($entity);
        $class = $meta->getManagerClass();
        return $this->managers[$entity] = new $class($this->getEntityManager(), $meta);
    }

    public function registerManager(string $entity, $manager) : void {
        $meta = $this->getMetadataRegistry()->get($entity);
        $class = $meta->getManagerClass();

        if ($class && !($manager instanceof $class)) {
            throw new \InvalidArgumentException("Invalid manager for entity '$entity', must be an instance of '$class'");
        }

        $this->managers[$entity] = $manager;
    }


    public function getMetadataProvider() : Metadata\Provider {
        return $this->metadataProvider ?? $this->metadataProvider = new Metadata\Provider(
            $this->createCacheStorage('metadata', [Metadata\Provider::class, 'serialize']),
            $this->getNamingStrategy()
        );
    }

    public function getMetadataRegistry() : Metadata\Registry {
        return $this->metadataRegistry ?? $this->metadataRegistry = new Metadata\Registry(
            $this->getMetadataProvider(),
            $this->config['entities']
        );
    }

    public function getNamingStrategy() : Metadata\INamingStrategy {
        if ($this->namingStrategy) {
            return $this->namingStrategy;
        }

        $strategy = $this->config['namingStrategy'];

        if (is_string($strategy)) {
            if (!class_exists($strategy) && class_exists($tmp = 'PORM\\Metadata\\NamingStrategy\\' . ucfirst($strategy))) {
                $strategy = $tmp;
            }

            $strategy = new $strategy();
        }

        if ($strategy instanceof Metadata\INamingStrategy) {
            return $this->namingStrategy = $strategy;
        } else {
            throw new \RuntimeException("Invalid naming strategy, expected a string or an instance of " . Metadata\INamingStrategy::class);
        }
    }

    public function getMapper() : Mapper {
        return $this->mapper ?? $this->mapper = new Mapper($this->getPlatform());
    }

    public function getTranslator() : SQL\Translator {
        return $this->translator ?? $this->translator = new SQL\Translator(
            $this->getASTParser(),
            $this->getMetadataRegistry(),
            $this->getPlatform(),
            $this->getEventDispatcher(),
            $this->createCacheStorage('query', [SQL\Translator::class, 'serialize'])
        );
    }


    public function getMigrationResolver() : Migrations\Resolver {
        return $this->migrationResolver ?? $this->migrationResolver = new Migrations\Resolver(
            $this->getDriver(),
            $this->getPlatform(),
            $this->config['migrationsDir']
        );
    }

    public function getMigrationRunner() : Migrations\Runner {
        return $this->migrationRunner ?? $this->migrationRunner = new Migrations\Runner($this->getDriver());
    }

    public function createRunMigrationsCommand() : Command\RunMigrationsCommand {
        return new Command\RunMigrationsCommand($this->getMigrationResolver(), $this->getMigrationRunner());
    }


    public function getTracyPanel() : Bridges\Tracy\Panel {
        if (!$this->tracyPanel) {
            $this->tracyPanel = new Bridges\Tracy\Panel();
            $this->tracyPanel->register();
        }

        return $this->tracyPanel;
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

    private function getASTParser() : SQL\AST\Parser {
        return $this->astParser ?? $this->astParser = new SQL\AST\Parser();
    }


    private function getASTBuilder() : SQL\AST\Builder {
        return $this->astBuilder ?? $this->astBuilder = new SQL\AST\Builder(
            $this->getMetadataRegistry(),
            $this->getASTParser(),
            $this->getPlatform()
        );
    }


    private function createCacheStorage(string $namespace) : ?Cache\IStorage {
        return call_user_func_array($this->getCacheStorageFactory(), func_get_args());
    }

    private function createDefaultCacheStorage(string $namespace, ?callable $serializer = null) : ?Cache\IStorage {
        return $this->cacheDir ? new Cache\Storage\PhpStorage($this->cacheDir, $namespace, $serializer) : null;
    }

}
