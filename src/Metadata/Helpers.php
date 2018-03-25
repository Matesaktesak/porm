<?php

declare(strict_types=1);

namespace PORM\Metadata;


class Helpers {

    public static function extractEntityMetadata(\ReflectionClass $entity, INamingStrategy $namingStrategy) : array {
        $meta = [
            'entityClass' => $entity->getName(),
        ];

        $annotations = AnnotationParser::parse($entity->getDocComment() ?: null);

        if (isset($annotations['Table'])) {
            $meta['tableName'] = $annotations['Table'][0] ?? $annotations['Table']['name'] ?? '';
        }

        if (empty($meta['tableName'])) {
            $meta['tableName'] = $namingStrategy->formatTableName($entity->getName(), $annotations);
        }

        $namingStrategy->setTableContext($meta['tableName'], $annotations);

        if (isset($annotations['Manager'])) {
            $meta['managerClass'] = $annotations['Manager'][0] ?? $annotations['Manager']['class'] ?? null;
        }

        if (empty($meta['managerClass'])) {
            $class = str_replace(['\\Entity\\', '\\Entities\\'], ['\\Manager\\', '\\Managers\\'], $entity->getName()) . 'Manager';
            $meta['managerClass'] = class_exists($class) ? $class : null;
        }

        $meta += [
            'properties' => [],
            'relations' => [],
            'aggregateProperties' => [],
        ];

        foreach ($entity->getProperties() as $property) {
            $annotations = AnnotationParser::parse($property->getDocComment() ?: null);

            if (isset($annotations['Column']) || key_exists('Column', $annotations)) {
                $meta['properties'][$property->getName()] = self::extractPropertyMetadata($entity, $property, $namingStrategy, $annotations);
            } else if (isset($annotations['Relation']) || key_exists('Relation', $annotations)) {
                $meta['relations'][$property->getName()] = self::extractRelationMetadata($entity, $property, $annotations);
            } else if (isset($annotations['Aggregate'])) {
                $meta['aggregateProperties'][$property->getName()] = self::extractAggregateMetadata($annotations);
            }
        }

        return self::expandMeta($meta);
    }


    private static function extractPropertyMetadata(\ReflectionClass $entity, \ReflectionProperty $property, INamingStrategy $namingStrategy, array $annotations) : array {
        $info = [
            'column' => $annotations['Column'][0]
                ?? $annotations['Column']['name']
                ?? $namingStrategy->formatColumnName($property->getName(), $annotations),
        ];

        if ($type = $annotations['Column']['type'] ?? null) {
            $info['type'] = trim($type, '\\[]');

            if (substr($type, -2) === '[]') {
                $info['type'] = 'json';
                $info['values'] = $type;
            }
        }

        if (!empty($annotations['Column']['nullable'])) {
            $info['nullable'] = true;
        }

        if (!empty($annotations['Column']['id'])) {
            $info['id'] = true;
        }

        if (!empty($annotations['Column']['generated'])) {
            $info['generated'] = true;
        } else if (!empty($annotations['Column']['generator'])) {
            $info['generator'] = $annotations['Column']['generator'];
        }

        if (!empty($info['generator']) && $info['generator'] === 'auto') {
            $info['generator'] = $namingStrategy->formatGeneratorName($property->getName(), $info['column'], $annotations);
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

    private static function extractRelationMetadata(\ReflectionClass $entity, \ReflectionProperty $property, array $annotations) : array {
        $info = [
            'target' => $annotations['Relation'][0] ?? $annotations['Relation']['target'] ?? null,
        ];

        if (!empty($annotations['Relation']['fk'])) {
            $info['fk'] = $annotations['Relation']['fk'];
        }

        if (!empty($annotations['Relation']['orderBy'])) {
            $info['orderBy'] = $annotations['Relation']['orderBy'];
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

        if (empty($info['target'])) {
            throw new MetadataException("Unable to determine relation target for property '{$property->getName()}' of entity '{$entity->getName()}'");
        } else if (empty($info['property'])) {
            if ($prop = self::extractInverseRelationTarget(new \ReflectionClass($info['target']), $entity, $property->getName())) {
                $info['property'] = $prop;
            } else if (empty($info['fk'])) {
                throw new MetadataException("Unable to determine inverse relation target for property '{$property->getName()}' of entity '{$entity->getName()}'");
            }
        }

        return $info;
    }

    private static function extractInverseRelationTarget(\ReflectionClass $entity, \ReflectionClass $targetEntity, string $relation) : ?string {
        $candidates = [];

        foreach ($entity->getProperties() as $property) {
            $annotations = AnnotationParser::parse($property->getDocComment() ?: null);

            if (isset($annotations['Relation']) || key_exists('Relation', $annotations)) {
                $target = $annotations['Relation'][0] ?? $annotations['Relation']['target'] ?? null;

                if (empty($target)) {
                    $method = 'get' . ucfirst($property->getName());

                    if ($entity->hasMethod($method) && ($hint = $entity->getMethod($method)->getReturnType()) && $hint->getName() !== 'array') {
                        $target = $hint->getName();
                    } else {
                        continue;
                    }
                }

                @list($ent, $prop) = explode('.', $target, 2);

                if (mb_strpos($ent, '\\') === false) {
                    $ent = ltrim($entity->getNamespaceName() . '\\' . $ent, '\\');
                }

                if ($ent === $targetEntity->getName()) {
                    if ($prop === $relation) {
                        return $property->getName();
                    } else if (!$prop) {
                        $candidates[] = $property->getName();
                    }
                }
            }
        }

        return count($candidates) === 1 ? reset($candidates) : null;
    }

    private static function extractAggregateMetadata(array $annotations) : array {
        $info = [
            'type' => $annotations['Aggregate']['type'] ?? 'COUNT',
        ];

        if (isset($annotations['Aggregate'][0])) {
            if (($p = mb_strpos($annotations['Aggregate'][0], '.')) !== false) {
                $info['relation'] = mb_substr($annotations['Aggregate'][0], 0, $p);
                $info['property'] = mb_substr($annotations['Aggregate'][0], $p + 1);
            } else {
                $info['relation'] = $annotations['Aggregate'][0];
                $info['property'] = 'id';
            }
        } else if (isset($annotations['Aggregate']['relation'])) {
            $info['relation'] = $annotations['Aggregate']['relation'];
            $info['property'] = $annotations['Aggregate']['property'] ?? 'id';
        } else {
            throw new MetadataException("Invalid @Aggregate annotation, missing the 'relation' attribute");
        }

        $info['where'] = $annotations['Aggregate']['where'] ?? null;

        return $info;
    }

    private static function expandMeta(array $meta) : array {
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
                $meta['generatedProperty'] = $prop;
            }
        }

        foreach ($meta['aggregateProperties'] as $prop => $info) {
            if (!isset($meta['relations'][$info['relation']])) {
                throw new MetadataException("Invalid aggregate property '$prop': relation '{$info['relation']}' doesn't exist");
            } else if (empty($meta['relations'][$info['relation']]['collection'])) {
                throw new MetadataException("Cannot aggregate a *-to-One relation");
            }
        }

        return $meta;
    }

}
