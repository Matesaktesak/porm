<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;

use PORM\SQL\InvalidQueryException;


abstract class Query extends Node {


    private $paramIdx = -1;


    public function mapResource(array $fields, ?string $alias = null, ?string $entity = null) : void {
        if ($alias && $this->hasMappedResource($alias)) {
            throw new InvalidQueryException("Duplicate alias '$alias'");
        }

        if ($alias) {
            $this->attributes['resources'][$alias] = [
                'fields' => $fields,
                'entity' => $entity,
            ];
        } else {
            $this->attributes['resources'][] = [
                'fields' => $fields,
                'entity' => $entity,
            ];
        }
    }

    public function getMappedResources() : array {
        return $this->attributes['resources'] ?? [];
    }

    public function hasMappedResource(string $alias) : bool {
        return isset($this->attributes['resources'][$alias]);
    }

    public function hasMappedEntity(string $alias) : bool {
        return isset($this->attributes['resources'][$alias]['entity']);
    }

    public function getMappedResourceFields(string $alias) : array {
        return $this->attributes['resources'][$alias]['fields'];
    }

    public function getMappedEntity(string $alias) : string {
        return $this->attributes['resources'][$alias]['entity'];
    }


    public function mapResultField(string $field, string $alias, ?string $type = null, ?bool $nullable = null) : void {
        if ($this->hasResultField($alias)) {
            throw new InvalidQueryException("Duplicate result field alias '$alias'");
        }

        $this->attributes['resultFields'][$alias] = [
            'field' => $field,
            'type' => $type,
            'nullable' => $nullable,
        ];
    }

    public function hasResultField(string $alias) : bool {
        return isset($this->attributes['resultFields'][$alias]);
    }

    public function getResultFieldInfo(string $alias) : array {
        return $this->attributes['resultFields'][$alias];
    }

    public function getResultFields() : array {
        return $this->attributes['resultFields'] ?? [];
    }

    public function registerRequiredParameter(?string $key = null, ?string $type = null, ?bool $nullable = null) : void {
        $this->attributes['parameterMap'][] = [
            'key' => $key ?? ++$this->paramIdx,
            'type' => $type,
            'nullable' => $nullable,
        ];
    }

    public function registerFixedParameter($value, ?string $type = null, ?bool $nullable = null) : void {
        $this->attributes['parameterMap'][] = [
            'key' => null,
            'type' => $type,
            'nullable' => $nullable,
            'value' => $value,
        ];
    }

    public function getParameterMap() : array {
        return $this->attributes['parameterMap'] ?? [];
    }

}
