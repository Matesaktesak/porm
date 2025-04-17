<?php

declare(strict_types=1);

namespace PORM;

use PORM\Metadata\Entity;
use PORM\SQL\AST\Node as AST;


class QueryBuilder {

    private SQL\Translator $translator;

    private SQL\AST\Builder $astBuilder;

    /** @var Entity|QueryBuilder|string */
    private string|null|QueryBuilder|Entity $from = null;

    private ?string $alias;

    private $fields = null;

    private array $join = [];

    private $where = null;

    private array $groupBy = [];

    private $having = null;

    private $orderBy = null;

    private $limit = null;

    private $offset = null;

    private array $union = [];


    public function __construct(SQL\Translator $translator, SQL\AST\Builder $astBuilder, ?Metadata\Entity $entity = null, ?string $alias = null) {
        $this->translator = $translator;
        $this->astBuilder = $astBuilder;
        $this->from = $entity;
        $this->alias = $alias;
    }


    public function select(array $fields) : self {
        $this->fields = $fields;
        return $this;
    }

    public function from($relation, ?string $alias = null) : self {
        if (!is_string($relation) && !($relation instanceof QueryBuilder) && !($relation instanceof Entity)) {
            throw new \InvalidArgumentException(
                'Invalid argument, expected a string, an instance of ' . QueryBuilder::class .
                ' or an instance of ' . Entity::class
            );
        }

        $this->from = $relation;
        $this->alias = $alias;
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

    public function where(?array $where) : self {
        $this->where = $where;
        return $this;
    }

    public function andWhere(array $where) : self {
        $this->where[] = $where;
        return $this;
    }

    public function orWhere(array $where) : self {
        $this->where[] = 'OR';
        $this->where[] = $where;
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

    public function orderBy(?array $orderBy) : self {
        $this->orderBy = $orderBy;
        return $this;
    }

    public function limit(?int $limit) : self {
        $this->limit = $limit;
        return $this;
    }

    public function offset(?int $offset) : self {
        $this->offset = $offset;
        return $this;
    }

    public function union(QueryBuilder $builder) : self {
        $this->union[] = new AST\UnionClause($builder->getAST());
        return $this;
    }

    public function unionAll(QueryBuilder $builder) : self {
        $this->union[] = new AST\UnionClause($builder->getAST(), true);
        return $this;
    }


    public function getAST() : AST\SelectQuery {
        $query = new AST\SelectQuery();

        if ($this->from instanceof Entity) {
            if (!$this->fields) {
                $this->fields = $this->from->getProperties();
            }

            $this->from = $this->from->getEntityClass();
        }

        if (is_string($this->from)) {
            $query->from[] = new AST\TableExpression(new AST\TableReference($this->from), $this->alias);
        } else {
            $query->from[] = new AST\TableExpression(new AST\SubqueryExpression($this->from->getAST()), $this->alias);
        }

        $query->fields = $this->astBuilder->buildResultFields($this->fields, $this->alias);

        foreach ($this->join as $join) {
            if ($join['relation'] instanceof QueryBuilder) {
                $expr = new AST\JoinExpression(new AST\SubqueryExpression($join['relation']->getAST()), $join['type'], $join['alias']);
            } else {
                $expr = new AST\JoinExpression(new AST\TableReference($join['relation']), $join['type'], $join['alias']);
            }

            if ($join['condition']) {
                $expr->condition = $this->astBuilder->buildConditionalExpression($join['condition']);
            }

            $query->from[] = $expr;
        }

        $this->astBuilder->applyCommonClauses($query, $this->where, $this->orderBy, $this->limit, $this->offset);

        foreach ($this->groupBy as $prop) {
            $query->groupBy[] = new AST\Identifier($prop);
        }

        $query->having = $this->astBuilder->buildConditionalExpression($this->having);
        $query->union = $this->union;

        return $query;
    }


    public function getQuery() : SQL\Query {
        return $this->translator->compile($this->getAST());
    }

}
