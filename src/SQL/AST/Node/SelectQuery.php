<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class SelectQuery extends Query implements IDataSource {
    use WhereClauseTrait;
    use CommonClausesTrait;

    /** @var ResultField[] */
    public $fields = [];

    /** @var TableExpression[] */
    public $from = [];

    /** @var Identifier[] */
    public $groupBy = [];

    /** @var Expression|null */
    public $having = null;

    /** @var UnionClause[] */
    public $union = [];



    public function getTraversableProperties() : array {
        return [
            'from' => true,
            'fields' => true,
            'where' => false,
            'groupBy' => true,
            'having' => false,
            'orderBy' => true,
            'limit' => false,
            'offset' => false,
            'union' => true,
        ];
    }

}
