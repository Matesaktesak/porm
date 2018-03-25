<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class UpdateQuery extends Query {
    use WhereClauseTrait;
    use CommonClausesTrait;
    use ReturningClauseTrait;

    /** @var TableExpression */
    public $table;

    /** @var AssignmentExpression[] */
    public $data = [];



    public function getTraversableProperties() : array {
        return [
            'table' => false,
            'data' => true,
            'where' => false,
            'orderBy' => true,
            'limit' => false,
            'offset' => false,
            'returning' => true,
        ];
    }

}
