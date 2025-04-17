<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class SelectQuery extends Query implements IDataSource {
    use WhereClauseTrait;
    use CommonClausesTrait;

    /** @var ResultField[] */
    public array $fields = [];

    /** @var TableExpression[] */
    public array $from = [];

    /** @var Identifier[] */
    public array $groupBy = [];

    /** @var Expression|null */
    public ?Expression $having = null;

    /** @var UnionClause[] */
    public array $union = [];



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
