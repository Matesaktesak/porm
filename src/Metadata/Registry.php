<?php

declare(strict_types=1);

namespace PORM\Metadata;

use PORM\Cache;


class Registry {

    private $compiler;

    private $cacheStorage;

    private $namespaces;


    /** @var Entity[] */
    private $meta = [];

    private $classMap = [];

    private $classMapAuthoritative = false;


    public function __construct(Compiler $compiler, ?Cache\IStorage $cacheStorage = null, ?array $namespaces = null) {
        $this->compiler = $compiler;
        $this->cacheStorage = $cacheStorage;
        $this->namespaces = $namespaces;
    }

    public function setClassMap(array $classMap) : void {
        $this->classMap = $classMap;
        $this->classMapAuthoritative = true;
    }


    public function get(string $entityClass) : Entity {
        $entityClass = $this->normalizeEntityClass($entityClass);

        if (!isset($this->meta[$entityClass])) {
            $this->load($entityClass);
        }

        return $this->meta[$entityClass];
    }


    public function has(string $entityClass) : bool {
        $entityClass = $this->normalizeEntityClass($entityClass);
        return isset($this->meta[$entityClass]) || !$this->classMapAuthoritative && class_exists($entityClass);
    }


    public function normalizeEntityClass(string $entityClass) : string {
        if (strpos($entityClass, '\\') !== false) {
            return $entityClass;
        } else if (isset($this->classMap[$entityClass])) {
            return $this->classMap[$entityClass];
        } else if (key_exists($entityClass, $this->classMap)) {
            throw new \RuntimeException("Ambiguous entity identifier '$entityClass'");
        } else if ($this->classMapAuthoritative) {
            throw new \RuntimeException("Unknown entity class '$entityClass'");
        } else if (strpos($entityClass, ':') !== false) {
            list ($alias, $entity) = explode(':', $entityClass, 2);

            if (isset($this->namespaces[$alias])) {
                return $this->classMap[$entityClass] = $this->namespaces[$alias] . '\\' . $entity;
            } else {
                return $this->classMap[$entityClass] = $alias . '\\Entity\\' . $entity;
            }
        } else if ($this->namespaces) {
            foreach ($this->namespaces as $namespace) {
                if (class_exists($namespace . '\\' . $entityClass)) {
                    return $this->classMap[$entityClass] = $namespace . '\\' . $entityClass;
                }
            }
        }

        return $this->classMap[$entityClass] = $entityClass;
    }


    private function load(string $entityClass) : void {
        if ($this->cacheStorage) {
            $this->meta[$entityClass] = $this->cacheStorage->get(
                $this->getCacheKey($entityClass),
                function() use ($entityClass) {
                    $compiled = $this->compile($entityClass);
                    $wanted = $compiled[$entityClass];
                    unset($compiled[$entityClass]);

                    foreach ($compiled as $class => $meta) {
                        $this->cacheStorage->get(
                            $this->getCacheKey($class),
                            function() use ($meta) {
                                return $meta;
                            }
                        );
                    }

                    return $wanted;
                }
            );
        } else {
            $this->compile($entityClass);
        }
    }

    private function compile(string $entityClass) : array {
        $meta = $this->compiler->compile($entityClass);
        $this->meta += $meta;
        return $meta;
    }


    public function compileClassMap() : void {
        $this->meta = $this->compiler->compile(... array_values(array_unique(array_filter($this->classMap))));

        if (!$this->cacheStorage) {
            return;
        }

        foreach ($this->meta as $class => $meta) {
            $this->cacheStorage->get(
                $this->getCacheKey($class),
                function() use ($meta) {
                    return $meta;
                }
            );
        }
    }


    public static function serialize(Entity $entity) : string {
        return Cache\Helpers::serializeInstance($entity, [
            'entityClass' => 'Entity class',
            'managerClass' => 'Manager class',
            'tableName' => 'Table name',
            'readonly' => 'Readonly',
            'properties' => 'Properties',
            'relations' => 'Relations',
            'aggregateProperties' => 'Aggregate properties',
            'propertyMap' => 'Property map',
            'columnMap' => 'Column map',
            'relationMap' => 'Relation map',
            'identifierProperties' => 'Identifier properties',
            'generatedProperty' => 'Generated property',
        ]);
    }


    private function getCacheKey(string $entityClass) : string {
        return sha1($entityClass);
    }
}
