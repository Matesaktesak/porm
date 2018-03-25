<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class TableExpression extends Node {

    /** @var ITable */
    public $table;

    /** @var Identifier|null */
    public $alias = null;


    public function __construct(ITable $table, ?string $alias = null) {
        $this->table = $table;

        if (isset($alias)) {
            $this->alias = new Identifier($alias);
        }
    }


    public function getTraversableProperties() : array {
        return [
            'table' => false,
            'alias' => false,
        ];
    }

}
