<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class ParameterReference extends Expression {


    public static function replacing(NamedParameterReference $param) : self {
        $ref = new static();
        $ref->attributes = $param->attributes;
        $ref->attributes['replaces'] = $param->name;
        return $ref;
    }


    public static function withValue($value, ?string $type = null, ?bool $nullable = null) : self {
        $ref = new static($type, $nullable);
        $ref->setValue($value);
        return $ref;
    }


    public function __construct(?string $type = null, ?bool $nullable = null) {
        $this->attributes['type'] = $type;
        $this->attributes['nullable'] = $nullable;
    }

    public function setId(int $id) : void {
        $this->attributes['id'] = $id;
    }

    public function getId() : int {
        return $this->attributes['id'];
    }

    public function hasInfo() : bool {
        return isset($this->attributes['type']) || isset($this->attributes['nullable']);
    }

    public function getInfo() : array {
        return [
            'type' => $this->attributes['type'],
            'nullable' => $this->attributes['nullable'],
        ];
    }

    public function setValue($value) : void {
        $this->attributes['value'] = $value;
    }

    public function hasValue() : bool {
        return isset($this->attributes['value']) || key_exists('value', $this->attributes);
    }

    public function getValue() {
        return $this->attributes['value'];
    }

}
