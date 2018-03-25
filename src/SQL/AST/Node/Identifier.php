<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class Identifier extends Expression {

    /** @var string */
    public $value;


    public function __construct(string $value) {
        $this->value = $value;
    }


    public function setTypeInfo(?string $type = null, ?bool $nullable = null) : void {
        $this->attributes['type'] = $type;
        $this->attributes['nullable'] = $nullable;
    }

    public function hasTypeInfo() : bool {
        return isset($this->attributes['type']) || key_exists('type', $this->attributes);
    }

    public function getTypeInfo() : array {
        return [
            'type' => $this->attributes['type'],
            'nullable' => $this->attributes['nullable'],
        ];
    }

    public function setMappingInfo(string $entity, string $property) : void {
        $this->attributes['mapping'] = [
            'entity' => $entity,
            'property' => $property,
        ];
    }

    public function hasMappingInfo() : bool {
        return isset($this->attributes['mapping']);
    }

    public function getMappingInfo() : array {
        return $this->attributes['mapping'];
    }

}
