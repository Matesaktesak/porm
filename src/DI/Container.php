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
use PORM\Command;


class Container {

    /** @var Factory */
    private $factory;

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



    public function __construct(array $config, ?string $cacheDir = null) {
        $this->factory = new Factory($config, $cacheDir);
    }




    public function getConnection() : Connection {
        return $this->connection ?? $this->connection = $this->factory->createConnection($this->getDriver(), $this->getPlatform());
    }


    public function getDriver() : Drivers\IDriver {
        return $this->driver ?? $this->driver = $this->factory->createDriver();
    }

    public function getPlatform() : Drivers\IPlatform {
        return $this->platform ?? $this->platform = $this->factory->createPlatform();
    }

    public function getEventDispatcher() : EventDispatcher {
        return $this->eventDispatcher ?? $this->eventDispatcher = $this->factory->createEventDispatcher();
    }

    public function getEntityManager() : EntityManager {
        return $this->entityManager ?? $this->entityManager = $this->factory->createEntityManager(
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

        return $this->managers[$entity] = $this->factory->createManager($this->getMetadataRegistry(), $this->getEntityManager(), $entity);
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
        return $this->metadataProvider ?? $this->metadataProvider = $this->factory->createMetadataProvider(
            $this->factory->createCacheStorage('metadata', [Metadata\Provider::class, 'serialize']),
            $this->getNamingStrategy()
        );
    }

    public function getMetadataRegistry() : Metadata\Registry {
        return $this->metadataRegistry ?? $this->metadataRegistry = $this->factory->createMetadataRegistry(
            $this->getMetadataProvider()
        );
    }

    public function getNamingStrategy() : Metadata\INamingStrategy {
        return $this->namingStrategy ?? $this->namingStrategy = $this->factory->createNamingStrategy();
    }

    public function getMapper() : Mapper {
        return $this->mapper ?? $this->mapper = $this->factory->createMapper($this->getPlatform());
    }

    public function getTranslator() : SQL\Translator {
        return $this->translator ?? $this->translator = $this->factory->createTranslator(
            $this->getASTParser(),
            $this->getMetadataRegistry(),
            $this->getPlatform(),
            $this->getEventDispatcher(),
            $this->factory->createCacheStorage('query', [SQL\Translator::class, 'serialize'])
        );
    }


    public function getMigrationResolver() : Migrations\Resolver {
        return $this->migrationResolver ?? $this->migrationResolver = $this->factory->createMigrationResolver(
            $this->getDriver(),
            $this->getPlatform()
        );
    }

    public function getMigrationRunner() : Migrations\Runner {
        return $this->migrationRunner ?? $this->migrationRunner = $this->factory->createMigrationRunner($this->getDriver());
    }

    public function getRunMigrationsCommand() : Command\RunMigrationsCommand {
        return $this->factory->createRunMigrationsCommand($this->getMigrationResolver(), $this->getMigrationRunner());
    }


    public function getTracyPanel() : Bridges\Tracy\Panel {
        if (!$this->tracyPanel) {
            $this->tracyPanel = $this->factory->createTracyPanel();
            $this->tracyPanel->register();
        }

        return $this->tracyPanel;
    }

    public function getASTParser() : SQL\AST\Parser {
        return $this->astParser ?? $this->astParser = $this->factory->createASTParser();
    }


    public function getASTBuilder() : SQL\AST\Builder {
        return $this->astBuilder ?? $this->astBuilder = $this->factory->createASTBuilder(
                $this->getMetadataRegistry(),
                $this->getASTParser(),
                $this->getPlatform()
            );
    }




}
