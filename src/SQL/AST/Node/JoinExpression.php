<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class JoinExpression extends TableExpression {

    public const JOIN_LEFT = 'LEFT',
        JOIN_INNER = 'INNER';

    /** @var string */
    public $type = self::JOIN_LEFT;

    /** @var Expression|null */
    public $condition = null;


    public function __construct(ITable $table, string $type, ?string $alias = null) {
        parent::__construct($table, $alias);
        $this->type = $type;
    }

    public function getTraversableProperties() : array {
        return [
            'table' => false,
            'alias' => false,
            'condition' => false,
        ];
    }

}
