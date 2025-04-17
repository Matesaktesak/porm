<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class UnionClause extends Node {

    /** @var SelectQuery */
    public SelectQuery $query;

    /** @var bool */
    public bool $all;


    public function __construct(SelectQuery $query, bool $all = false) {
        $this->query = $query;
        $this->all = $all;
    }


    public function getTraversableProperties() : array {
        return [
            'query' => false,
        ];
    }

}
