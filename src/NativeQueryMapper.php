<?php

declare(strict_types=1);

namespace PORM;


class NativeQueryMapper {
    private string $query;

    private array $parameterMap = [];

    private array $resultFields = [];

    public function __construct(string $query) {
        $this->query = $query;
    }

    public function addParameter($value, ?string $type = null, ?bool $nullable = null) : void {
        $this->parameterMap[] = [
            'key' => null,
            'type' => $type,
            'nullable' => $nullable,
            'value' => $value,
        ];
    }

    public function setParameters(array $parameters) : void {
        $this->parameterMap = [];

        foreach ($parameters as $param) {
            $this->addParameter($param);
        }
    }

    public function addScalarField(string $field, ?string $alias = null, ?string $type = null, ?bool $nullable = null) : void {
        $this->resultFields[$alias ?? $field] = [
            'alias' => $field,
            'type' => $type,
            'nullable' => $nullable,
        ];
    }

    public function addEntityField(Metadata\Entity $meta, string $property, ?string $alias = null) : void {
        $info = $meta->getPropertyInfo($property);
        $this->addScalarField($property, $alias, $info['type'], $info['nullable']);
    }

    public function addEntityFields(Metadata\Entity $meta) : void {
        foreach ($meta->getPropertyMap() as $prop => $field) {
            $this->addEntityField($meta, $prop, $field);
        }
    }

    public function getQuery() : SQL\Query {
        return new SQL\Query($this->query, $this->parameterMap, $this->resultFields);
    }
}
