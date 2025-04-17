<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class OrderExpression extends Node {

    /** @var Expression */
    public Expression $value;

    /** @var bool */
    public bool $ascending;


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
