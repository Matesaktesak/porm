<?php

declare(strict_types=1);

namespace PORM\Metadata\CompilerPass;

use PORM\Exceptions\MetadataException;
use PORM\Metadata\Compiler;
use PORM\Metadata\ICompilerPass;


class PropertyInfoPass implements ICompilerPass {

    public function process(\ReflectionClass $entity, array & $meta, Compiler $compiler) : void {
        $meta['properties'] = [];

        foreach ($entity->getProperties() as $property) {
            $annotations = $compiler->getAnnotations($property);

            if (isset($annotations['Column']) || key_exists('Column', $annotations)) {
                $meta['properties'][$property->getName()] = $this->processProperty(
                    $entity,
                    $property,
                    $annotations['Column'],
                    $compiler
                );
            }
        }
    }


    private function processProperty(
        \ReflectionClass $entity,
        \ReflectionProperty $property,
        ?array $annotation,
        Compiler $compiler
    ) : array {
        $info = [
            'column' => $annotation[0]
                ?? $annotation['name']
                ?? $compiler->getNamingStrategy()->formatColumnName($entity, $property, $compiler),
        ];

        if ($type = $annotation['type'] ?? null) {
            $info['type'] = trim($type, '\\[]');

            if (substr($type, -2) === '[]') {
                $info['type'] = 'json';
                $info['values'] = $type;
            }
        }

        if (!empty($annotation['nullable'])) {
            $info['nullable'] = true;
        }

        if (!empty($annotation['id'])) {
            $info['id'] = true;
        }

        if (!empty($annotation['generated'])) {
            $info['generated'] = true;
        } else if (!empty($annotation['generator'])) {
            $info['generator'] = $annotation['generator'];
        }

        if (!empty($info['generator']) && $info['generator'] === 'auto') {
            $info['generator'] = $compiler->getNamingStrategy()->formatColumnName($entity, $property, $compiler);
        }

        if (empty($info['type'])) {
            $method = ucfirst($property->getName());

            if ($entity->hasMethod('get' . $method)) {
                $method = $entity->getMethod('get' . $method);
            } else if ($entity->hasMethod('is' . $method)) {
                $method = $entity->getMethod('is' . $method);
            } else {
                throw new MetadataException('Unable to determine property type for ' . $entity->getName() . '::$' . $property->getName());
            }

            $type = $method->getReturnType();

            switch (ltrim($type->getName(), '\\')) {
                case 'string':
                case 'int':
                case 'float':
                case 'bool':
                    $info['type'] = $type->getName();
                    break;
                case 'array':
                    $info['type'] = 'json';
                    break;
                case 'DateTime':
                case 'DateTimeImmutable':
                    $info['type'] = 'datetime';
                    break;
                default:
                    throw new MetadataException('Unable to determine property type for ' . $entity->getName() . '::$' . $property->getName() . ', no type specified in @Column declaration and getter returns an object');
            }

            if ($type && $type->allowsNull()) {
                $info['nullable'] = true;
            }
        }

        if (empty($info['nullable'])) {
            $info['nullable'] = false;
        }

        return $info;
    }

}
