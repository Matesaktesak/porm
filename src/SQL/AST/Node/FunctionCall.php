<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class FunctionCall extends Expression {

    /** @var string */
    public string $name;

    /** @var ExpressionList */
    public ?ExpressionList $arguments = null;


    public function __construct(string $name, Expression ... $arguments) {
        $this->name = $name;

        if ($arguments) {
            $this->arguments = new ExpressionList(... $arguments);
        }
    }

    public function getTraversableProperties() : array {
        return [
            'arguments' => false,
        ];
    }

}
