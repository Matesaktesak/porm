<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class TableExpression extends Node {

    /** @var ITable */
    public ITable $table;

    /** @var Identifier|null */
    public ?Identifier $alias = null;


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
