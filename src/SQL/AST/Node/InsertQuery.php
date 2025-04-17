<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class InsertQuery extends Query {
    use ReturningClauseTrait;

    /** @var TableReference */
    public TableReference $into;

    /** @var Identifier[] */
    public array $fields = [];

    /** @var IDataSource */
    public IDataSource $dataSource;



    public function getTraversableProperties() : array {
        return [
            'into' => false,
            'fields' => true,
            'dataSource' => false,
            'returning' => true,
        ];
    }

}
