<?php

declare(strict_types=1);

namespace PORM\Bridges\NetteDI;

use Composer\Autoload\ClassLoader;
use Nette\PhpGenerator;
use Nette\DI\ContainerBuilder;
use Nette\DI\Statement;
use PORM\DI\Factory;
use PORM\Metadata;
use PORM\SQL;
use Nette\DI\CompilerExtension;
use Symfony\Component\Console\Application;


class PORMExtension extends CompilerExtension {

    private $defaults = Factory::DEFAULTS;

    private $cacheDir;

    private $debugMode;


    public function __construct(?string $cacheDir = null, ?bool $debugMode = null) {
        $this->cacheDir = $cacheDir;
        $this->debugMode = $debugMode;
    }


    public function loadConfiguration() : void {
        $builder = $this->getContainerBuilder();
        $config = $this->validateConfig($this->defaults);

        if ($config['debugger'] === null) {
            if ($this->debugMode === null) {
                $this->debugMode = $builder->parameters['debugMode'];
            }

            $config['debugger'] = $this->debugMode;
        }

        $builder->addDefinition($this->prefix('factory'))
            ->setType(Factory::class)
            ->setArguments(['config' => $config, 'cacheDir' => $this->cacheDir])
            ->setAutowired(false);

        $builder->addDefinition($this->prefix('connection'))
            ->setFactory($this->prefix('@factory::createConnection'));

        $builder->addDefinition($this->prefix('connection.driver'))
            ->setFactory($this->prefix('@factory::createDriver'));

        $builder->addDefinition($this->prefix('connection.platform'))
            ->setFactory($this->prefix('@factory::createPlatform'));

        $builder->addDefinition($this->prefix('events.dispatcher'))
            ->setFactory($this->prefix('@factory::createEventDispatcher'))
            ->addSetup('setListenerResolver', [['@container', 'getService']]);

        $builder->addDefinition($this->prefix('entity.manager'))
            ->setFactory($this->prefix('@factory::createEntityManager'));

        $builder->addDefinition($this->prefix('entity.mapper'))
            ->setFactory($this->prefix('@factory::createMapper'));

        $builder->addDefinition($this->prefix('metadata.compiler'))
            ->setFactory($this->prefix('@factory::createMetadataCompiler'));

        $builder->addDefinition($this->prefix('metadata.registry'))
            ->setFactory($this->prefix('@factory::createMetadataRegistry'))
            ->setArguments([
                'cacheStorage' => new Statement($this->prefix('@factory::createCacheStorage'), ['metadata', [Metadata\Registry::class, 'serialize']]),
            ]);

        $builder->addDefinition($this->prefix('metadata.namingStrategy'))
            ->setFactory($this->prefix('@factory::createNamingStrategy'));

        $builder->addDefinition($this->prefix('sql.translator'))
            ->setFactory($this->prefix('@factory::createTranslator'))
            ->setArguments([
                'cacheStorage' => new Statement($this->prefix('@factory::createCacheStorage'), ['query', [SQL\Translator::class, 'serialize']]),
            ]);

        $builder->addDefinition($this->prefix('sql.ast.parser'))
            ->setFactory($this->prefix('@factory::createASTParser'));

        $builder->addDefinition($this->prefix('sql.ast.builder'))
            ->setFactory($this->prefix('@factory::createASTBuilder'));

        $builder->addDefinition($this->prefix('migrations.resolver'))
            ->setFactory($this->prefix('@factory::createMigrationResolver'));

        $builder->addDefinition($this->prefix('migrations.runner'))
            ->setFactory($this->prefix('@factory::createMigrationRunner'));


        if ($config['debugger']) {
            $builder->addDefinition($this->prefix('debugger'))
                ->setFactory($this->prefix('@factory::createTracyPanel'));

            $builder->getDefinition($this->prefix('connection'))
                ->addSetup('addListener', [[$this->prefix('@debugger'), 'logEvent']]);
        }
    }

    public function beforeCompile() : void {
        $builder = $this->getContainerBuilder();
        $config = $this->getConfig();

        if (class_exists(ClassLoader::class) && !empty($config['entities'])) {
            $this->setupEntities($builder, $config);
        }

        if (class_exists(Application::class) && ($application = $builder->getByType(Application::class))) {
            $builder->getDefinition($application)
                ->addSetup('add', [new Statement($this->prefix('@factory::createRunMigrationsCommand'))]);
        }

        $this->registerEventListeners($builder);
    }

    public function afterCompile(PhpGenerator\ClassType $class) : void {
        $builder = $this->getContainerBuilder();
        $init = $class->getMethod('initialize');

        if ($builder->hasDefinition($this->prefix('debugger'))) {
            $init->addBody($builder->formatPhp('?;', [
                new Statement($this->prefix('@debugger::register')),
            ]));
        }
    }


    private function setupEntities(ContainerBuilder $builder, array $config) : void {
        $finder = new Metadata\EntityFinder();
        $map = $finder->getEntityClassMap($config['entities']);
        $def = $builder->getDefinition($this->prefix('metadata.registry'));
        $def->addSetup('setClassMap', [$map]);

        $factory = new Factory($config, $this->cacheDir);
        $ns = $factory->createNamingStrategy();
        $cs = $factory->createCacheStorage('metadata', [Metadata\Registry::class, 'serialize']);
        $cmp = $factory->createMetadataCompiler($ns);
        $registry = $factory->createMetadataRegistry($cmp, $cs);
        $registry->setClassMap($map);
        $registry->compileClassMap();

        foreach (array_unique(array_filter($map)) as $entityClass) {
            $meta = $registry->get($entityClass);
            $manager = $meta->getManagerClass();

            if ($manager && !$builder->getByType($manager)) {
                $constructor = (new \ReflectionClass($manager))->getConstructor();
                $manager = $builder->addDefinition($this->prefix('manager.' . lcfirst($meta->getReflection()->getShortName())))
                    ->setType($manager);

                if ($constructor) {
                    foreach ($constructor->getParameters() as $param) {
                        if ($param->hasType() && $param->getType()->getName() === Metadata\Entity::class) {
                            $manager->setArguments([
                                $param->getName() => new Statement($this->prefix('@metadata.registry::get'), [$entityClass])
                            ]);
                            break;
                        }
                    }
                }
            }
        }
    }


    private function registerEventListeners(ContainerBuilder $builder) : void {
        $eventDispatcher = $builder->getDefinition($this->prefix('events.dispatcher'));
        $listeners = $builder->findByTag('porm.entity.listener');

        foreach ($listeners as $listener => $options) {
            if (!isset($options['map'])) {
                if (isset($options['event'])) {
                    $options['events'] = (array) $options['event'];
                }

                if (isset($options['entity'])) {
                    $options['entities'] = (array) $options['entity'];
                }

                $options['map'] = array_fill_keys($options['entities'], $options['events']);
            }

            foreach ($options['map'] as $entity => $events) {
                foreach ($events as $event) {
                    $eventDispatcher->addSetup('addListener', [
                        'event' => $entity . '::' . $event,
                        'listener' => (string) $listener,
                        'method' => $options['method'] ?? null,
                    ]);
                }
            }
        }

        $listeners = $builder->findByTag('porm.event.listener');

        foreach ($listeners as $listener => $options) {
            if (isset($options['event'])) {
                $options['events'] = (array) $options['event'];
            }

            foreach ($options['events'] as $event) {
                $eventDispatcher->addSetup('addListener', [
                    'event' => $event,
                    'listener' => (string) $listener,
                    'method' => $options['method'] ?? null,
                ]);
            }
        }
    }

}
