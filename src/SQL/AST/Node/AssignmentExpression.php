<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class AssignmentExpression extends Node {

    /** @var Identifier */
    public Identifier $target;

    /** @var Expression */
    public Expression $value;


    public function __construct(Identifier $target, Expression $value) {
        $this->target = $target;
        $this->value = $value;
    }


    public function getTraversableProperties() : array {
        return [
            'target' => false,
            'value' => false,
        ];
    }

}
