<?php

declare(strict_types=1);

namespace PORM\Metadata\CompilerPass;

use PORM\Exceptions\MetadataException;
use PORM\Metadata\Compiler;
use PORM\Metadata\ICompilerPass;


class RelationInfoPass implements ICompilerPass {

    public function process(\ReflectionClass $entity, array & $meta, Compiler $compiler) : void {
        $meta['relations'] = [];

        foreach ($entity->getProperties() as $property) {
            $annotations = $compiler->getAnnotations($property);

            if (isset($annotations['Relation']) || key_exists('Relation', $annotations)) {
                $meta['relations'][$property->getName()] = $this->processProperty(
                    $entity,
                    $property,
                    $annotations['Relation'],
                    $meta['properties']
                );
            }
        }
    }

    private function processProperty(
        \ReflectionClass $entity,
        \ReflectionProperty $property,
        ?array $annotation,
        array $properties
    ) : array {
        $info = [
            'target' => $annotation[0] ?? $annotation['target'] ?? null,
        ];

        if (!empty($annotation['fk'])) {
            if (isset($properties[$annotation['fk']])) {
                $info['fk'] = $annotation['fk'];
            } else {
                throw new MetadataException(
                    "Foreign key property '{$annotation['fk']}' referenced in relation '{$property->getName()}' " .
                    "of entity '{$entity->getName()}' does not exist"
                );
            }
        } else if (!empty($annotation['via'])) {
            $info['via'] = $annotation['via'];
        }

        if (!empty($annotation['where'])) {
            $info['where'] = $annotation['where'];
        }

        if (!empty($annotation['orderBy'])) {
            $info['orderBy'] = $annotation['orderBy'];
        }

        if (!empty($info['target'])) {
            @list($ent, $prop) = explode('.', $info['target'], 2);

            $info['target'] = ltrim(
                mb_strpos($ent, '\\') === false ? $entity->getNamespaceName() . '\\' . $ent : $ent,
                '\\'
            );

            if (!empty($prop)) {
                $info['property'] = $prop;
            }
        }

        $method = 'get' . ucfirst($property->getName());

        if ($entity->hasMethod($method) && ($hint = $entity->getMethod($method)->getReturnType())) {
            if ($hint->getName() === 'array') {
                $info['collection'] = true;
            } else if (empty($info['target'])) {
                $info['target'] = $hint->getName();
            }
        }

        if (!empty($info['via']) && empty($info['collection'])) {
            throw new MetadataException("The 'via' parameter can only be used with M:N relations, but relation '{$property->getName()} of entity '{$entity->getName()}' is not a collection");
        }

        if (empty($info['target'])) {
            throw new MetadataException("Unable to determine relation target for property '{$property->getName()}' of entity '{$entity->getName()}'");
        }

        return $info;
    }

}
