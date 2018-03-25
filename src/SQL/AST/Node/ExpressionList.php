<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class ExpressionList extends Expression {

    /** @var Expression[] */
    public $expressions = [];


    public function __construct(Expression ... $expressions) {
        $this->expressions = $expressions;
    }


    public function getTraversableProperties() : array {
        return [
            'expressions' => true,
        ];
    }

}
