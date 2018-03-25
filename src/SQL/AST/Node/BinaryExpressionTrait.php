<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


trait BinaryExpressionTrait {

    /** @var Expression */
    public $left;

    /** @var string */
    public $operator;

    /** @var Expression */
    public $right;

    public function __construct(Expression $left, string $operator, Expression $right) {
        $this->left = $left;
        $this->operator = $operator;
        $this->right = $right;
    }


    public function getTraversableProperties() : array {
        return [
            'left' => false,
            'right' => false,
        ];
    }

}
