<?php

declare(strict_types=1);

namespace PORM\Metadata\CompilerPass;

use PORM\Metadata\Compiler;
use PORM\Metadata\ICompilerPass;


class EntityInfoPass implements ICompilerPass {

    public function process(\ReflectionClass $entity, array & $meta, Compiler $compiler) : void {
        $annotations = $compiler->getAnnotations($entity);
        $meta['entityClass'] = $entity->getName();

        if (isset($annotations['Table'])) {
            $meta['tableName'] = $annotations['Table'][0] ?? $annotations['Table']['name'] ?? '';
        } else if (isset($annotations['View'])) {
            $meta['tableName'] = $annotations['View'][0] ?? $annotations['View']['name'] ?? '';
            $meta['readonly'] = true;
        }

        if (empty($meta['tableName'])) {
            $meta['tableName'] = $compiler->getNamingStrategy()->formatTableName($entity, $compiler);
        }

        if (isset($annotations['Manager'])) {
            $meta['managerClass'] = $annotations['Manager'][0] ?? $annotations['Manager']['class'] ?? null;
        }

        if (empty($meta['managerClass'])) {
            $class = str_replace(['\\Entity\\', '\\Entities\\'], ['\\Manager\\', '\\Managers\\'], $entity->getName()) . 'Manager';
            $meta['managerClass'] = class_exists($class) ? $class : null;
        }

        if (!isset($meta['readonly'])) {
            $meta['readonly'] = false;
        }
    }

}
