<?php

declare(strict_types=1);

namespace PORM\Metadata\CompilerPass;

use PORM\Exceptions\MetadataException;
use PORM\Metadata\Compiler;
use PORM\Metadata\ICompilerPass;


class MappingCompletionPass implements ICompilerPass {

    public function process(\ReflectionClass $entity, array & $meta, Compiler $compiler) : void {
        $this->completeMappingInfo($entity, $meta);
    }


    private function completeMappingInfo(\ReflectionClass $entity, array & $meta) : void {
        $meta['propertyMap'] = [];
        $meta['columnMap'] = [];
        $meta['relationMap'] = [];
        $meta['identifierProperties'] = [];
        $meta['generatedProperty'] = null;

        foreach ($meta['relations'] as $prop => $info) {
            if (!empty($info['property'])) {
                $meta['relationMap'][$info['target']][$info['property']] = $prop;
            }
        }

        foreach ($meta['properties'] as $prop => $info) {
            $meta['columnMap'][$info['column']] = $prop;
            $meta['propertyMap'][$prop] = $info['column'];

            if (!empty($info['id'])) {
                $meta['identifierProperties'][] = $prop;
            }

            if (!empty($info['generated']) || !empty($info['generator'])) {
                if (empty($meta['generatedProperty'])) {
                    $meta['generatedProperty'] = $prop;
                } else {
                    throw new MetadataException("Multiple generated properties defined in entity '{$entity->getName()}'");
                }
            }
        }
    }

}
