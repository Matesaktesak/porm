<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class TableReference extends Node implements ITable {

    /** @var Identifier */
    public $name;


    public function __construct(string $name) {
        $this->name = new Identifier($name);
    }

    public function getTraversableProperties() : array {
        return [
            'name' => false,
        ];
    }

}
