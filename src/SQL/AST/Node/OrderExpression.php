<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class OrderExpression extends Node {

    /** @var Expression */
    public $value;

    /** @var bool */
    public $ascending;


    public function __construct(Expression $value, bool $ascending = true) {
        $this->value = $value;
        $this->ascending = $ascending;
    }

    public function getTraversableProperties() : array {
        return [
            'value' => false,
        ];
    }

}
