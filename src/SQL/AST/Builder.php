<?php

declare(strict_types=1);

namespace PORM\SQL\AST;

use PORM\Drivers\IPlatform;
use PORM\Lookup;
use PORM\Metadata\Entity;
use PORM\Metadata\Registry;
use PORM\SQL\Expression;
use PORM\SQL\InvalidQueryException;


class Builder {

    private $metadataRegistry;

    private $platform;

    private $parser;



    public function __construct(Registry $metadataRegistry, Parser $parser, IPlatform $platform) {
        $this->metadataRegistry = $metadataRegistry;
        $this->parser = $parser;
        $this->platform = $platform;
    }


    public function buildLookupSelectQuery(Lookup $lookup, bool $onlyCount = false) : Node\SelectQuery {
        $meta = $lookup->getEntityMetadata();

        if ($onlyCount) {
            $id = $meta->getSingleIdentifierProperty() ?: '*';
            $fields = ['_count' => new Expression('COUNT(_root.' . $id . ')')];
        } else {
            $fields = null;
        }

        $query = $this->buildSelectQuery(
            $meta,
            $fields,
            $lookup->getWhere(),
            $lookup->getOrderBy(),
            $lookup->getLimit(),
            $lookup->getOffset()
        );

        $this->joinRequiredRelations($query, $meta, $lookup->getRelations());

        return $query;
    }

    public function buildLookupUpdateQuery(Lookup $lookup, array $data) : Node\UpdateQuery {
        return $this->buildUpdateQuery(
            $lookup->getEntityMetadata(),
            $data,
            $lookup->getWhere(),
            $lookup->getOrderBy(),
            $lookup->getLimit(),
            $lookup->getOffset()
        );
    }

    public function buildLookupDeleteQuery(Lookup $lookup) : Node\DeleteQuery {
        return $this->buildDeleteQuery(
            $lookup->getEntityMetadata(),
            $lookup->getWhere(),
            $lookup->getOrderBy(),
            $lookup->getLimit(),
            $lookup->getOffset()
        );
    }


    public function buildSelectQuery(Entity $meta, ?array $fields = null, ?array $where = null, ?array $orderBy = null, ?int $limit = null, ?int $offset = null) : Node\SelectQuery {
        $query = new Node\SelectQuery();
        $query->from[] = new Node\TableExpression(new Node\TableReference($meta->getEntityClass()), '_root');

        if (!$fields) {
            $fields = $meta->getProperties();
        }

        $query->fields = $this->buildResultFields($fields);
        $this->applyCommonClauses($query, $where, $orderBy, $limit, $offset);
        return $query;
    }

    public function buildInsertQuery(Entity $meta, array ... $rows) : Node\InsertQuery {
        $query = new Node\InsertQuery();
        $query->into = new Node\TableReference($meta->getEntityClass());
        $query->fields = $rows ? $this->buildFieldList(array_keys(reset($rows))) : [];
        $query->dataSource = $this->buildValuesExpression($meta, ... $rows);

        if ($meta->hasGeneratedProperty() && $this->platform->supportsReturningClause() && !key_exists($prop = $meta->getGeneratedProperty(), $rows)) {
            $query->returning[] = new Node\ResultField(new Node\Identifier($prop), '_generated');
            $query->mapResultField('_generated', $prop);
        }

        return $query;
    }

    public function buildUpdateQuery(Entity $meta, array $data, ?array $where = null, ?array $orderBy, ?int $limit = null, ?int $offset = null) : Node\UpdateQuery {
        $query = new Node\UpdateQuery();
        $query->table = new Node\TableExpression(new Node\TableReference($meta->getEntityClass()), '_root');
        $query->data = $this->buildAssignmentExpressionList($meta, $data);
        $this->applyCommonClauses($query, $where, $orderBy, $limit, $offset);
        return $query;
    }

    public function buildDeleteQuery(Entity $meta, ?array $where = null, ?array $orderBy, ?int $limit = null, ?int $offset = null) : Node\DeleteQuery {
        $query = new Node\DeleteQuery();
        $query->from = new Node\TableReference($meta->getEntityClass());
        $this->applyCommonClauses($query, $where, $orderBy, $offset, $limit);
        return $query;
    }


    private function buildResultFields(array $fields) : array {
        $resultFields = [];

        foreach ($fields as $alias => $value) { /** @var Expression|string $value */
            if ($value instanceof Expression) {
                $value = $this->extractExpression($value);
            } else {
                $value = new Node\Identifier('_root.' . $value);
            }

            $resultFields[] = new Node\ResultField($value, is_numeric($alias) ? null : $alias);
        }

        return $resultFields;
    }



    private function applyCommonClauses(Node\Query $query, ?array $where = null, ?array $orderBy = null, ?int $limit = null, ?int $offset = null) : void {
        /** @var Node\SelectQuery|Node\UpdateQuery|Node\DeleteQuery $query */
        $query->where = $this->buildConditionalExpression($where);
        $query->orderBy = $this->buildOrderExpressionList($orderBy);
        $query->limit = $limit !== null ? Node\Literal::int($limit) : null;
        $query->offset = $offset !== null ? Node\Literal::int($offset) : null;
    }



    private function joinRequiredRelations(Node\SelectQuery $query, Entity $meta, array $relations) : void {
        $id = $meta->getSingleIdentifierProperty();
        $sub = 0;

        if (!$id) {
            return;
        }

        foreach (array_keys(array_filter($relations)) as $relation) {
            if ($meta->hasRelation($relation)) {
                $info = $meta->getRelationInfo($relation);
                $relMeta = $this->metadataRegistry->get($info['target']);

                if (!empty($info['collection'])) {
                    if (!$relMeta->hasRelationTarget($meta->getEntityClass(), $relation)) {
                        throw new InvalidQueryException("Unable to determine inverse relation parameters for relation '$relation' of entity {$meta->getEntityClass()}");
                    }

                    $prop = $relMeta->getRelationTarget($meta->getEntityClass(), $relation);
                    $inv = $relMeta->getRelationInfo($prop);
                    $alias = '_sub_' . $sub;

                    $subq = new Node\SelectQuery();
                    $subq->fields[] = new Node\ResultField(new Node\Identifier($alias . '.' . $inv['fk']), $alias . '_fk');
                    $subq->from[] = new Node\TableExpression(new Node\TableReference($info['target']), $alias);
                    $subq->groupBy[] = new Node\Identifier($alias . '.' . $inv['fk']);

                    $join = new Node\JoinExpression(new Node\SubqueryExpression($subq), Node\JoinExpression::JOIN_INNER);
                    $join->alias = new Node\Identifier('_rel_' . $sub);
                    $join->condition = new Node\BinaryExpression(
                        new Node\Identifier('_rel_' . $sub . '.' . $alias . '_fk'),
                        '=',
                        new Node\Identifier('_root.' . $id)
                    );

                    $query->from[] = $join;
                    $sub++;
                } else {
                    $query->from[] = new Node\JoinExpression(new Node\TableReference($info['target']), Node\JoinExpression::JOIN_INNER);
                }
            } else {
                throw new InvalidQueryException("Entity {$meta->getEntityClass()} has no relation '$relation'");
            }
        }
    }


    private function buildConditionalExpression(?array $conditions) : ?Node\Expression {
        if (!$conditions) {
            return null;
        }

        $expression = $logicOp = $expr = null;

        foreach ($conditions as $property => $value) {
            if (is_numeric($property)) {
                if (is_array($value)) {
                    $expr = $this->buildConditionalExpression($value);
                } else if ($value instanceof Expression) {
                    $expr = $this->extractExpression($value);
                } else if (is_string($value) && preg_match('/^(and|or)$/i', $value, $m)) {
                    $logicOp = strtoupper($m[1]);
                    continue;
                } else {
                    throw new InvalidQueryException("Invalid condition");
                }
            } else {
                @list ($property, $op) = explode(' ', $property, 2);
                $op = strtoupper($op ?: '=');

                if ($value === null) {
                    $expr = $op === '!=' ? new Node\UnaryExpression('NOT', Node\Literal::null()) : Node\Literal::null();
                    $op = 'IS';
                } else if (in_array($op, ['=', '!=', '>', '>=', '<', '<=', 'CONTAINS', 'LIKE'], true)) {
                    $expr = $this->sanitizeValue($value);
                } else if (in_array($op, ['IN', 'NOT IN'], true)) {
                    $expr = new Node\ExpressionList(... $this->sanitizeValueList($value));
                } else {
                    throw new InvalidQueryException("Invalid operator '$op'");
                }

                $expr = new Node\BinaryExpression(new Node\Identifier($property), $op, $expr);
            }

            if ($expr && $expression) {
                $expression = new Node\LogicExpression(
                    $expression,
                    $logicOp ?: 'AND',
                    $expr
                );
            } else {
                $expression = $expr;
            }

            $logicOp = $expr = null;
        }

        return $expression;
    }


    private function buildAssignmentExpressionList(Entity $meta, ?array $data) : array {
        if (!$data) {
            return [];
        }

        $expressions = [];

        foreach ($data as $prop => $value) {
            $info = $meta->hasProperty($prop) ? $meta->getPropertyInfo($prop) : null;

            $expressions[] = new Node\AssignmentExpression(
                new Node\Identifier('_root.' . $prop),
                $this->sanitizeValue($value, $info['type'] ?? null, $info['nullable'] ?? null)
            );
        }

        return $expressions;
    }


    private function buildFieldList(array $fields) : array {
        return array_map(function(string $field) : Node\Identifier {
            return new Node\Identifier($field);
        }, $fields);
    }

    private function buildValuesExpression(Entity $meta, array ... $rows) : Node\ValuesExpression {
        $expr = new Node\ValuesExpression();

        foreach ($rows as $row) {
            $expr->dataSets[] = $set = new Node\ExpressionList();

            foreach ($row as $prop => $value) {
                $info = $meta->hasProperty($prop) ? $meta->getPropertyInfo($prop) : null;
                $set->expressions[] = $this->sanitizeValue($value, $info['type'] ?? null, $info['nullable'] ?? null);
            }
        }

        return $expr;
    }


    private function buildOrderExpressionList(?array $orderBy) : array {
        if (!$orderBy) {
            return [];
        }

        $expressions = [];

        foreach ($orderBy as $prop => $value) {
            if (is_numeric($prop)) {
                if (is_string($value)) {
                    $expressions[] = new Node\OrderExpression(new Node\Identifier($value));
                } else if ($value instanceof Expression) {
                    if (preg_match('~^(.+)\s+(ASC|DESC)$~i', $value->getSql(), $m)) {
                        $value = new Expression($m[1], $value->getParameters());
                        $asc = strtolower($m[2]) === 'asc';
                    } else {
                        $asc = true;
                    }

                    $expr = $this->extractExpression($value);
                    $expressions[] = new Node\OrderExpression($expr, $asc);
                } else {
                    throw new InvalidQueryException("Invalid order by value");
                }
            } else {
                $expressions[] = new Node\OrderExpression(
                    new Node\Identifier($prop),
                    is_bool($value) ? $value : (strtolower((string) $value) === 'asc')
                );
            }
        }

        return $expressions;
    }


    private function extractExpression(Expression $expression) : Node\Expression {
        $node = $this->parser->parseExpression($expression->getSql());

        if ($expression->hasParameters()) {
            $node->attributes['parameters'] = $expression->getParameters();
        }

        return $node;
    }

    private function sanitizeValueList($value) : array {
        if (!is_array($value)) {
            if (is_iterable($value)) {
                $value = iterator_to_array($value);
            } else {
                throw new InvalidQueryException("Invalid IN operand");
            }
        }

        return empty($value)
            ? [ Node\Literal::null() ]
            : array_map(function($v) {
                return $this->sanitizeValue($v);
            }, $value);
    }


    private function sanitizeValue($value, ?string $type = null, ?bool $nullable = null) : Node\Expression {
        if ($value instanceof Expression) {
            return $this->extractExpression($value);
        } else {
            return Node\ParameterReference::withValue($value, $type, $nullable);
        }
    }

}
