<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class SubqueryExpression extends Expression implements ITable {

    /** @var SelectQuery */
    public $query;

    public function __construct(SelectQuery $query) {
        $this->query = $query;
    }


    public function getTraversableProperties() : array {
        return [
            'query' => false,
        ];
    }

}
