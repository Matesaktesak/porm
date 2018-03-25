<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class ResultField extends Node {

    /** @var Expression */
    public $value;

    /** @var Identifier|null */
    public $alias = null;


    public function __construct(Expression $value, ?string $alias = null) {
        $this->value = $value;

        if (isset($alias)) {
            $this->alias = new Identifier($alias);
        }
    }

    public function getTraversableProperties() : array {
        return [
            'value' => false,
            'alias' => false,
        ];
    }

}
