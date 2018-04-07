<?php

declare(strict_types=1);

namespace PORM\Metadata;

use PORM\Cache;


class Provider {

    private $compiler;

    private $cache;


    public function __construct(Compiler $compiler, ?Cache\IStorage $cache = null) {
        $this->compiler = $compiler;
        $this->cache = $cache;
    }


    public function get(string $entityClass) : Entity {
        $entity = new \ReflectionClass($entityClass);

        if ($this->cache) {
            return $this->cache->get(
                $this->getCacheKey($entity),
                function() use ($entity) {
                    return $this->extract($entity);
                }
            );
        } else {
            return $this->extract($entity);
        }
    }

    private function extract(\ReflectionClass $entity) : Entity {


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
