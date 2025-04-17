<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class CaseExpression extends Expression {

    /** @var CaseBranch[] */
    public array $branches = [];

    /** @var Expression|null */
    public ?Expression $else = null;


    public function getTraversableProperties() : array {
        return [
            'branches' => true,
            'else' => false,
        ];
    }

}
