<?php

declare(strict_types=1);

namespace PORM;

use PORM\SQL\AST\Node as AST;


class QueryBuilder {

    private $translator;

    private $builder;

    private $entity;

    private $alias = null;

    private $fields = null;

    private $join = [];

    private $where = null;

    private $groupBy = [];

    private $having = null;

    private $orderBy = null;

    private $limit = null;

    private $offset = null;


    public function __construct(SQL\Translator $translator, SQL\AST\Builder $builder, Metadata\Entity $entity, ?string $alias = null) {
        $this->translator = $translator;
        $this->builder = $builder;
        $this->entity = $entity;
        $this->alias = $alias;
    }


    public function select(array $fields) : self {
        $this->fields = $fields;
        return $this;
    }

    public function join($relation, ?string $alias = null, ?array $condition = null, string $type = 'LEFT') : self {
        if (!is_string($relation) && !($relation instanceof QueryBuilder)) {
            throw new \InvalidArgumentException("Invalid argument, expected a string or an instance of " . QueryBuilder::class);
        }

        $this->join[] = [
            'type' => $type,
            'relation' => $relation,
            'alias' => $alias,
            'condition' => $condition,
        ];

        return $this;
    }

    public function leftJoin($relation, ?string $alias = null, ?array $condition = null) : self {
        return $this->join($relation, $alias, $condition, 'LEFT');
    }

    public function innerJoin($relation, ?string $alias = null, ?array $condition = null) : self {
        return $this->join($relation, $alias, $condition, 'INNER');
    }

    public function where(array $where) : self {
        $this->where = $where;
        return $this;
    }

    public function groupBy(array $groupBy) : self {
        $this->groupBy = $groupBy;
        return $this;
    }

    public function having(array $having) : self {
        $this->having = $having;
        return $this;
    }

    public function orderBy(array $orderBy) : self {
        $this->orderBy = $orderBy;
        return $this;
    }

    public function limit(int $limit) : self {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset) : self {
        $this->offset = $offset;
        return $this;
    }


    public function getAST() : AST\SelectQuery {
        $query = $this->builder->buildSelectQuery($this->entity, $this->alias, $this->fields, $this->where, $this->orderBy, $this->limit, $this->offset);

        foreach ($this->join as $join) {
            if ($join['relation'] instanceof QueryBuilder) {
                $expr = new AST\JoinExpression(new AST\SubqueryExpression($join['relation']->getAST()), $join['type'], $join['alias']);
            } else {
                $expr = new AST\JoinExpression(new AST\TableReference($join['relation']), $join['type'], $join['alias']);
            }

            if ($join['condition']) {
                $expr->condition = $this->builder->buildConditionalExpression($join['condition']);
            }

            $query->from[] = $expr;
        }

        foreach ($this->groupBy as $prop) {
            $query->groupBy[] = new AST\Identifier($prop);
        }

        $query->having = $this->builder->buildConditionalExpression($this->having);

        return $query;
    }


    public function getQuery() : SQL\Query {
        return $this->translator->compile($this->getAST());
    }

}
