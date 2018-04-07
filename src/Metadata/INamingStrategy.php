<?php

declare(strict_types=1);

namespace PORM\Metadata;


interface INamingStrategy {

    public function formatTableName(\ReflectionClass $entity, Compiler $compiler) : string;

    public function formatColumnName(\ReflectionClass $entity, \ReflectionProperty $property, Compiler $compiler) : string;

    public function formatGeneratorName(\ReflectionClass $entity, \ReflectionProperty $property, Compiler $compiler) : string;

    public function formatAssignmentTableName(\ReflectionClass $entity1, \ReflectionClass $entity2, Compiler $compiler) : string;

    public function formatAssignmentColumnName(\ReflectionClass $toEntity, \ReflectionProperty $toProperty, Compiler $compiler) : string;

}
