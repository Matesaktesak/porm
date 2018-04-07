<?php

declare(strict_types=1);

namespace PORM\Metadata;


class Compiler {

    private $namingStrategy;

    /** @var ICompilerPass[] */
    private $passes;

    private $classes;

    private $discovered;

    private $meta;

    private $reflections;

    /** @var \SplObjectStorage|array[] */
    private $annotations;


    public function __construct(INamingStrategy $namingStrategy) {
        $this->namingStrategy = $namingStrategy;
        $this->passes = [
            new CompilerPass\EntityInfoPass(),
            new CompilerPass\PropertyInfoPass(),
            new CompilerPass\RelationInfoPass(),
            new CompilerPass\AggregationInfoPass(),
            new CompilerPass\MappingCompletionPass(),
            new CompilerPass\DiscoveryPass(),
            new CompilerPass\RelationCompletionPass(),
        ];
    }

    public function addPass(ICompilerPass $pass, bool $prepend = false) : void {
        if ($prepend) {
            array_unshift($this->passes, $pass);
        } else {
            $this->passes[] = $pass;
        }
    }

    public function compile(string ... $classes) : array {
        $this->classes = $classes;
        $this->discovered = [];
        $this->meta = array_fill_keys($classes, []);
        $this->reflections = [];
        $this->annotations = new \SplObjectStorage();
        $applied = [];

        foreach ($this->passes as $pass) {
            foreach ($this->classes as $class) {
                $pass->process($this->getReflection($class), $this->meta[$class], $this);
            }

            $applied[] = $pass;

            while ($this->discovered) {
                $discovered = $this->discovered;
                $this->discovered = [];
                $this->meta += array_fill_keys($discovered, []);
                $this->classes = array_merge($this->classes, $discovered);

                foreach ($applied as $appliedPass) {
                    foreach ($discovered as $class) {
                        $appliedPass->process($this->getReflection($class), $this->meta[$class], $this);
                    }
                }
            }
        }

        return array_map(\Closure::fromCallable([$this, 'createMeta']), $this->meta);
    }


    public function getNamingStrategy() : INamingStrategy {
        return $this->namingStrategy;
    }

    public function hasClass(string $class) : bool {
        return in_array($class, $this->classes, true) || in_array($class, $this->discovered, true);
    }

    public function registerDiscoveredClass(string $class) : void {
        $this->discovered[] = $class;
    }

    public function getReflection(string $class) : \ReflectionClass {
        return $this->reflections[$class] ?? $this->reflections[$class] = new \ReflectionClass($class);
    }

    /**
     * @param \ReflectionClass|\ReflectionProperty $reflector
     * @return array
     */
    public function getAnnotations($reflector) : array {
        if (!$this->annotations->contains($reflector)) {
            $this->annotations[$reflector] = AnnotationParser::parse($reflector->getDocComment() ?: null);
        }

        return $this->annotations[$reflector];
    }

    public function getMeta(string $class) : array {
        return $this->meta[$class];
    }


    private function createMeta(array $meta) : Entity {
        return new Entity(
            $meta['entityClass'],
            $meta['managerClass'],
            $meta['tableName'],
            $meta['readonly'],
            $meta['properties'],
            $meta['relations'],
            $meta['aggregateProperties'],
            $meta['propertyMap'],
            $meta['columnMap'],
            $meta['relationMap'],
            $meta['identifierProperties'],
            $meta['generatedProperty']
        );
    }

}
