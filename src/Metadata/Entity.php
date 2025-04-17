<?php

declare(strict_types=1);

namespace PORM\Metadata;


class Entity {

    /** @var string */
    private string $entityClass;

    /** @var string */
    private ?string $managerClass;

    /** @var string */
    private string $tableName;

    /** @var bool */
    private bool $readonly;

    /** @var array */
    private array $properties;

    /** @var array */
    private array $relations;

    /** @var array */
    private array $aggregateProperties;

    /** @var array */
    private array $identifierProperties;

    /** @var array */
    private array $fieldMap;

    /** @var array */
    private array $propertyMap;

    /** @var array */
    private array $relationMap;

    /** @var string */
    private ?string $generatedProperty;

    /** @var \ReflectionClass */
    private ?\ReflectionClass $classReflection = null;

    /** @var \ReflectionProperty[] */
    private array $propertyReflections = [];


    public function __construct(
        string $entityClass,
        ?string $managerClass,
        string $tableName,
        bool $readonly,
        array $properties,
        array $relations,
        array $aggregateProperties,
        array $propertyMap,
        array $columnMap,
        array $relationMap,
        array $identifierProperties,
        ?string $generatedProperty
    ) {
        $this->entityClass = $entityClass;
        $this->managerClass = $managerClass;
        $this->tableName = $tableName;
        $this->readonly = $readonly;
        $this->properties = $properties;
        $this->relations = $relations;
        $this->aggregateProperties = $aggregateProperties;
        $this->propertyMap = $propertyMap;
        $this->fieldMap = $columnMap;
        $this->relationMap = $relationMap;
        $this->identifierProperties = $identifierProperties;
        $this->generatedProperty = $generatedProperty;
    }

    public function getEntityClass() : string {
        return $this->entityClass;
    }

    public function getManagerClass() : ?string {
        return $this->managerClass;
    }

    /**
     * @param null|string $property
     * @return \ReflectionClass|\ReflectionProperty
     */
    public function getReflection(?string $property = null) : \Reflector {
        if (!$property) {
            if (!$this->classReflection) {
                $this->classReflection = new \ReflectionClass($this->entityClass);
            }

            return $this->classReflection;
        } else {
            if (!isset($this->propertyReflections[$property])) {
                $this->propertyReflections[$property] = $this->getReflection()->getProperty($property);
            }

            return $this->propertyReflections[$property];
        }
    }

    public function getTableName() : string {
        return $this->tableName;
    }

    public function isReadonly() : bool {
        return $this->readonly;
    }

    public function getIdentifierProperties() : array {
        return $this->identifierProperties;
    }

    public function getSingleIdentifierProperty(bool $need = true) : ?string {
        if (($n = count($this->identifierProperties)) === 1) {
            return reset($this->identifierProperties);
        } else if ($need) {
            throw new \RuntimeException("Entity '{$this->entityClass}' has " . ($n ? 'a composite' : 'no') . " identifier");
        } else {
            return null;
        }
    }

    public function getPropertyInfo(string $property) : array {
        return $this->properties[$property];
    }

    public function getProperties() : array {
        return array_keys($this->propertyMap);
    }

    public function getPropertiesInfo() : array {
        return $this->properties;
    }

    public function getPropertyMap() : array {
        return $this->propertyMap;
    }

    public function hasProperty(string $property) : bool {
        return isset($this->propertyMap[$property]);
    }

    public function getFieldName(string $property) : string {
        return $this->propertyMap[$property];
    }

    public function getFields() : array {
        return array_keys($this->fieldMap);
    }

    public function getFieldMap() : array {
        return $this->fieldMap;
    }

    public function hasField(string $field) : bool {
        return isset($this->fieldMap[$field]);
    }

    public function getPropertyName(string $field) : string {
        return $this->fieldMap[$field];
    }

    public function hasGeneratedProperty() : bool {
        return $this->generatedProperty !== null;
    }

    public function getGeneratedProperty() : ?string {
        return $this->generatedProperty;
    }

    public function getRelations() : array {
        return array_keys($this->relations);
    }

    public function getRelationsInfo() : array {
        return $this->relations;
    }

    public function hasRelation(string $property) : bool {
        return isset($this->relations[$property]);
    }

    public function getRelationInfo(string $property) : array {
        if (!isset($this->relations[$property])) {
            throw new \InvalidArgumentException("Entity '{$this->entityClass}' has no relation '$property'");
        }

        return $this->relations[$property];
    }

    public function hasRelationTarget(string $entity, string $property) : bool {
        return isset($this->relationMap[$entity][$property]);
    }

    public function getRelationTarget(string $entity, string $property) : string {
        return $this->relationMap[$entity][$property];
    }

    public function hasAggregateProperty(string $property) : bool {
        return isset($this->aggregateProperties[$property]);
    }

    public function getAggregatePropertyInfo(string $property) : array {
        return $this->aggregateProperties[$property];
    }


    public function __sleep() : array {
        return [
            'entityClass',
            'managerClass',
            'tableName',
            'properties',
            'relations',
            'aggregateProperties',
            'identifierProperties',
            'fieldMap',
            'propertyMap',
            'relationMap',
            'generatedProperty',
        ];
    }

}
