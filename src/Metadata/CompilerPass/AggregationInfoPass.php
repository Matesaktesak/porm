<?php

declare(strict_types=1);

namespace PORM\Metadata\CompilerPass;

use PORM\Exceptions\MetadataException;
use PORM\Metadata\Compiler;
use PORM\Metadata\ICompilerPass;


class AggregationInfoPass implements ICompilerPass {

    public function process(\ReflectionClass $entity, array & $meta, Compiler $compiler) : void {
        $meta['aggregateProperties'] = [];

        foreach ($entity->getProperties() as $property) {
            $annotations = $compiler->getAnnotations($property);

            if (isset($annotations['Aggregate']) || key_exists('Aggregate', $annotations)) {
                $meta['aggregateProperties'][$property->getName()] = $this->processProperty(
                    $entity,
                    $property,
                    $annotations['Aggregate'],
                    $meta['relations']
                );
            }
        }
    }

    private function processProperty(
        \ReflectionClass $entity,
        \ReflectionProperty $property,
        ?array $annotation,
        array $relations
    ) : array {
        $info = [
            'type' => $annotation['type'] ?? 'COUNT',
        ];

        if (isset($annotation[0])) {
            @list($relation, $prop) = explode('.', $annotation[0]);

            if (!isset($relations[$relation])) {
                throw new MetadataException(
                    "Relation '$relation' referenced in aggregate property '{$property->getName()}' " .
                    "of entity '{$entity->getName()}' does not exist"
                );
            } else if (empty($relations[$relation]['collection'])) {
                throw new MetadataException("Cannot aggregate *-to-One relation '{$relation}' of entity '{$entity->getName()}'");
            }

            $info['relation'] = $relation;
            $info['property'] = $prop ?? 'id';
        } else if (isset($annotation['relation'])) {
            $info['relation'] = $annotation['relation'];
            $info['property'] = $annotation['property'] ?? 'id';
        } else {
            throw new MetadataException("Invalid @Aggregate annotation, missing the 'relation' attribute");
        }

        $info['where'] = $annotation['where'] ?? null;

        return $info;
    }

}
