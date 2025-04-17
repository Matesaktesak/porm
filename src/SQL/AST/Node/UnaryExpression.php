<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class UnaryExpression extends Expression {

    /** @var string */
    public string $operator;

    /** @var Expression */
    public Expression $argument;


    public function __construct(string $operator, Expression $argument) {
        $this->operator = $operator;
        $this->argument = $argument;
    }

    public function getTraversableProperties() : array {
        return [
            'argument' => false,
        ];
    }


}
