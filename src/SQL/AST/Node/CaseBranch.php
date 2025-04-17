<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class CaseBranch extends Node {

    /** @var Expression */
    public Expression $condition;

    /** @var Expression */
    public Expression $statement;

    public function getTraversableProperties() : array {
        return [
            'condition' => false,
            'statement' => false,
        ];
    }

}
