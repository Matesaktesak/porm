<?php

declare(strict_types=1);

namespace PORM\Metadata;

use PORM\Cache;


class Provider {

    private $cache;

    private $namingStrategy;


    public function __construct(?Cache\IStorage $cache = null, ?INamingStrategy $namingStrategy = null) {
        $this->cache = $cache;
        $this->namingStrategy = $namingStrategy;
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
        $meta = Helpers::extractEntityMetadata($entity, $this->getNamingStrategy());

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


    private function getCacheKey(\ReflectionClass $entity) : string {
        return sha1($entity->getName() . "\0" . filemtime($entity->getFileName()));
    }


    private function getNamingStrategy() : INamingStrategy {
        return $this->namingStrategy ?: $this->namingStrategy = new NamingStrategy\SnakeCase();
    }

}
