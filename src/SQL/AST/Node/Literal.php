<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class Literal extends Expression {

    public const
        TYPE_STRING = 'string',
        TYPE_INT = 'int',
        TYPE_FLOAT = 'float',
        TYPE_BOOL = 'bool',
        TYPE_NULL = 'null';

    /** @var string|int|float|bool|null */
    public $value;

    /** @var string */
    public $type;


    public static function string(string $value) : self {
        return new static($value, self::TYPE_STRING);
    }

    public static function int(int $value) : self {
        return new static($value, self::TYPE_INT);
    }

    public static function float(float $value) : self {
        return new static($value, self::TYPE_FLOAT);
    }

    public static function bool(bool $value) : self {
        return new static($value, self::TYPE_BOOL);
    }

    public static function null() : self {
        return new static(null, self::TYPE_NULL);
    }


    private function __construct($value, string $type) {
        $this->value = $value;
        $this->type = $type;
    }

}
