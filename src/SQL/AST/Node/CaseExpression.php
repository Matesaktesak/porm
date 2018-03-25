<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class CaseExpression extends Expression {

    /** @var CaseBranch[] */
    public $branches = [];

    /** @var Expression|null */
    public $else = null;


    public function getTraversableProperties() : array {
        return [
            'branches' => true,
            'else' => false,
        ];
    }

}
