<?php

declare(strict_types=1);

namespace PORM\Metadata;


class Registry {

    /** @var Provider */
    private $provider;

    /** @var array */
    private $namespaces;

    /** @var Entity[] */
    private $meta = [];

    /** @var array */
    private $classMap = [];


    public function __construct(Provider $provider, ?array $namespaces = null) {
        $this->provider = $provider;
        $this->namespaces = $namespaces;
    }


    public function get(string $entityClass) : Entity {
        $entityClass = $this->normalizeEntityClass($entityClass);
        return $this->meta[$entityClass] ?? $this->meta[$entityClass] = $this->provider->get($entityClass);
    }


    public function has(string $entityClass) : bool {
        $entityClass = $this->normalizeEntityClass($entityClass);
        return isset($this->meta[$entityClass]) || class_exists($entityClass);
    }


    public function normalizeEntityClass(string $entityClass) : string {
        if (strpos($entityClass, '\\') !== false) {
            return $entityClass;
        } else if (isset($this->classMap[$entityClass])) {
            return $this->classMap[$entityClass];
        } else if (key_exists($entityClass, $this->classMap)) {
            throw new \RuntimeException("Ambiguous entity identifier '$entityClass'");
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
}
