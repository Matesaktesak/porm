<?php

declare(strict_types=1);

namespace PORM\Metadata\NamingStrategy;

use PORM\Metadata\Compiler;
use PORM\Metadata\INamingStrategy;


class SnakeCase implements INamingStrategy {


    public function formatTableName(\ReflectionClass $entity, Compiler $compiler) : string {
        return $this->toSnakeCase($entity->getShortName());
    }

    public function formatColumnName(\ReflectionClass $entity, \ReflectionProperty $property, Compiler $compiler) : string {
        return $this->toSnakeCase($property->getName());
    }

    public function formatGeneratorName(\ReflectionClass $entity, \ReflectionProperty $property, Compiler $compiler) : string {
        return $this->toSnakeCase($entity->getShortName() . ucfirst($property->getName()) . 'Seq');
    }

    public function formatAssignmentTableName(\ReflectionClass $entity1, \ReflectionClass $entity2, Compiler $compiler) : string {
        return $this->toSnakeCase($entity1->getShortName() . 'To' . $entity2->getShortName());
    }

    public function formatAssignmentColumnName(\ReflectionClass $toEntity, \ReflectionProperty $toProperty, Compiler $compiler) : string {
        return $this->toSnakeCase($toEntity->getShortName() . ucfirst($toProperty->getName()));
    }


    protected function toSnakeCase(string $identifier) : string {
        return mb_strtolower(preg_replace('/(?<=[^A-Z])[A-Z]|(?<!^)[A-Z](?=[^A-Z])/', '_$0', $identifier));
    }

}
