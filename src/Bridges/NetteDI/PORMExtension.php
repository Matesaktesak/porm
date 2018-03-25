<?php

declare(strict_types=1);

namespace PORM\Bridges\NetteDI;

use Composer\Autoload\ClassLoader;
use Nette\DI\ContainerBuilder;
use Nette\DI\Statement;
use Nette\PhpGenerator\PhpLiteral;
use PORM\Factory;
use PORM\Metadata;
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
            ->setFactory($this->prefix('@factory::getConnection'));

        $builder->addDefinition($this->prefix('driver'))
            ->setFactory($this->prefix('@factory::getDriver'));

        $builder->addDefinition($this->prefix('platform'))
            ->setFactory($this->prefix('@factory::getPlatform'));

        $builder->addDefinition($this->prefix('events.dispatcher'))
            ->setFactory($this->prefix('@factory::getEventDispatcher'))
            ->addSetup('setListenerResolver', [['@container', 'getService']]);

        $builder->addDefinition($this->prefix('entityManager'))
            ->setFactory($this->prefix('@factory::getEntityManager'));

        $builder->addDefinition($this->prefix('metadata.provider'))
            ->setFactory($this->prefix('@factory::getMetadataProvider'))
            ->setAutowired(false);

        $builder->addDefinition($this->prefix('metadata.registry'))
            ->setFactory($this->prefix('@factory::getMetadataRegistry'));

        $builder->addDefinition($this->prefix('metadata.namingStrategy'))
            ->setFactory($this->prefix('@factory::getNamingStrategy'));

        $builder->addDefinition($this->prefix('translator'))
            ->setFactory($this->prefix('@factory::getTranslator'));

        $builder->addDefinition($this->prefix('migrations.resolver'))
            ->setFactory($this->prefix('@factory::getMigrationResolver'));

        $builder->addDefinition($this->prefix('migrations.runner'))
            ->setFactory($this->prefix('@factory::getMigrationRunner'));

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


    private function setupEntities(ContainerBuilder $builder, array $config) : void {
        $finder = new Metadata\EntityFinder();
        $map = $finder->getEntityClassMap($config['entities']);
        $def = $builder->getDefinition($this->prefix('metadata.registry'));
        $def->addSetup('\\Closure::bind(function() { $this->classMap = ?; }, $service, ?)->__invoke()', [$map, Metadata\Registry::class]);

        $factory = new Factory($config, $this->cacheDir);
        $provider = $factory->getMetadataProvider();

        foreach (array_unique(array_filter($map)) as $entityClass) {
            $meta = $provider->get($entityClass);
            $manager = $meta->getManagerClass();

            if ($manager && !$builder->getByType($manager)) {
                $builder->addDefinition($this->prefix('manager.' . lcfirst($meta->getReflection()->getShortName())))
                    ->setType($manager)
                    ->setArguments(['metadata' => new Statement($this->prefix('@metadata.registry::get'), [$entityClass])])
                    ->addSetup($this->prefix('@factory::registerManager'), ['entity' => $entityClass, 'manager' => new PhpLiteral('$service')]);
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
