<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class DeleteQuery extends Query {
    use WhereClauseTrait;
    use CommonClausesTrait;
    use ReturningClauseTrait;

    /** @var TableExpression */
    public $from;


    public function getTraversableProperties() : array {
        return [
            'from' => false,
            'where' => false,
            'orderBy' => true,
            'limit' => false,
            'offset' => false,
            'returning' => true,
        ];
    }

}
