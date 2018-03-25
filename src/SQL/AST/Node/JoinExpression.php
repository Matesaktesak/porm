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


    public function __construct(ITable $table, string $type) {
        parent::__construct($table);
        $this->type = $type;
    }

    public function getTraversableProperties() : array {
        return [
            'table' => false,
            'condition' => false,
            'alias' => false,
        ];
    }


    public function setRelationInfo(string $from, string $property) : void {
        $this->attributes['relation'] = [
            'from' => $from,
            'property' => $property,
        ];
    }

    public function isRelation() : bool {
        return isset($this->attributes['relation']);
    }

    public function getRelationInfo() : ?array {
        return $this->attributes['relation'] ?? null;
    }

}
