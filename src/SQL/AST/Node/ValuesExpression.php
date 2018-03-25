<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class ValuesExpression extends Node implements IDataSource {

    /** @var ExpressionList[] */
    public $dataSets = [];


    public function getTraversableProperties() : array {
        return [
            'dataSets' => true,
        ];
    }

}
