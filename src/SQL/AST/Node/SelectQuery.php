<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class SelectQuery extends Query implements IDataSource {
    use WhereClauseTrait;
    use CommonClausesTrait;

    /** @var UnionClause|null */
    public $unionWith = null;

    /** @var ResultField[] */
    public $fields = [];

    /** @var TableExpression[] */
    public $from = [];

    /** @var Identifier[] */
    public $groupBy = [];

    /** @var Expression|null */
    public $having = null;



    public function getTraversableProperties() : array {
        return [
            'unionWith' => false,
            'from' => true,
            'fields' => true,
            'where' => false,
            'groupBy' => true,
            'having' => false,
            'orderBy' => true,
            'limit' => false,
            'offset' => false,
        ];
    }

}
