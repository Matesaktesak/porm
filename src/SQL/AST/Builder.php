<?php

declare(strict_types=1);

namespace PORM\SQL\AST;

use PORM\Drivers\IPlatform;
use PORM\Metadata\Provider;
use PORM\SQL\Expression;
use PORM\Exceptions\InvalidQueryException;


class Builder {
    private Provider $metadataProvider;
    private Parser $parser;
    private IPlatform $platform;

    public function __construct(Provider $metadataProvider, Parser $parser, IPlatform $platform) {
        $this->metadataProvider = $metadataProvider;
        $this->parser = $parser;
        $this->platform = $platform;
    }


    public function buildSelectQuery(?string $from = null, ?string $alias = null, ?array $fields = null, ?array $where = null, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): Node\SelectQuery {
        $query = new Node\SelectQuery();

        if ($from) {
            $query->from[] = new Node\TableExpression(new Node\TableReference($from), $alias);
        }

        if ($fields) {
            $query->fields = $this->buildResultFields($fields, $alias);
        }

        $this->applyCommonClauses($query, $where, $orderBy, $limit, $offset);
        return $query;
    }

    public function buildInsertQuery(string $into, ?array $info, array ...$rows): Node\InsertQuery {
        $query = new Node\InsertQuery();
        $query->into = new Node\TableReference($into);
        $query->fields = $rows ? $this->buildFieldList(array_keys(reset($rows))) : [];
        $query->dataSource = $this->buildValuesExpression($info, ... $rows);
        return $query;
    }

    public function buildUpdateQuery(string $table, ?string $alias, array $info, array $data, ?array $where = null, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): Node\UpdateQuery {
        $query = new Node\UpdateQuery();
        $query->table = new Node\TableExpression(new Node\TableReference($table), $alias);
        $query->data = $this->buildAssignmentExpressionList($info, $data, $alias);
        $this->applyCommonClauses($query, $where, $orderBy, $limit, $offset);
        return $query;
    }

    public function buildDeleteQuery(string $from, ?string $alias = null, ?array $where = null, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): Node\DeleteQuery {
        $query = new Node\DeleteQuery();
        $query->from = new Node\TableExpression(new Node\TableReference($from), $alias);
        $this->applyCommonClauses($query, $where, $orderBy, $offset, $limit);
        return $query;
    }

    public function buildConditionalExpression(?array $conditions): ?Node\Expression {
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


    public function buildResultFields(array $fields, ?string $alias = null): array {
        $resultFields = [];
        $alias = $alias ? $alias . '.' : '';

        foreach ($fields as $as => $value) {
            /** @var Expression|Node\Expression|string $value */
            if ($value instanceof Expression) {
                $value = $this->extractExpression($value);
            } else if (!($value instanceof Node\Expression)) {
                $value = new Node\Identifier(strpos($value, '.') === false ? $alias . $value : $value);
            }

            $resultFields[] = new Node\ResultField($value, is_numeric($as) ? null : $as);
        }

        return $resultFields;
    }


    public function applyCommonClauses(Node\Query $query, ?array $where = null, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): void {
        /** @var Node\SelectQuery|Node\UpdateQuery|Node\DeleteQuery $query */
        $query->where = $this->buildConditionalExpression($where);
        $query->orderBy = $this->buildOrderExpressionList($orderBy);
        $query->limit = $limit !== null ? Node\Literal::int($limit) : null;
        $query->offset = $offset !== null ? Node\Literal::int($offset) : null;
    }


    private function buildAssignmentExpressionList(array $info, ?array $data, ?string $alias = null): array {
        if (!$data) {
            return [];
        }

        $expressions = [];
        $alias = $alias ? $alias . '.' : '';

        foreach ($data as $prop => $value) {
            $nfo = $info[$prop] ?? null;

            $expressions[] = new Node\AssignmentExpression(
                new Node\Identifier($alias . $prop),
                $this->sanitizeValue($value, $nfo['type'] ?? null, $nfo['nullable'] ?? null)
            );
        }

        return $expressions;
    }


    private function buildFieldList(array $fields): array {
        return array_map(function (string $field): Node\Identifier {
            return new Node\Identifier($field);
        }, $fields);
    }

    private function buildValuesExpression(?array $info, array ...$rows): Node\ValuesExpression {
        $expr = new Node\ValuesExpression();

        foreach ($rows as $row) {
            $expr->dataSets[] = $set = new Node\ExpressionList();

            foreach ($row as $prop => $value) {
                $nfo = $info[$prop] ?? null;
                $set->expressions[] = $this->sanitizeValue($value, $nfo['type'] ?? null, $nfo['nullable'] ?? null);
            }
        }

        return $expr;
    }


    private function buildOrderExpressionList(?array $orderBy): array {
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
                    is_bool($value) ? $value : (strtolower((string)$value) === 'asc')
                );
            }
        }

        return $expressions;
    }


    private function extractExpression(Expression $expression): Node\Expression {
        $node = $this->parser->parseExpression($expression->getSql());

        if ($expression->hasParameters()) {
            $node->attributes['parameters'] = $expression->getParameters();
        }

        return $node;
    }

    private function sanitizeValueList($value): array {
        if (!is_array($value)) {
            if (is_iterable($value)) {
                $value = iterator_to_array($value);
            } else {
                throw new InvalidQueryException("Invalid IN operand");
            }
        }

        return empty($value)
            ? [Node\Literal::null()]
            : array_map(function ($v) {
                return $this->sanitizeValue($v);
            }, $value);
    }


    private function sanitizeValue($value, ?string $type = null, ?bool $nullable = null): Node\Expression {
        if ($value instanceof Expression) {
            return $this->extractExpression($value);
        } else {
            return Node\ParameterReference::withValue($value, $type, $nullable);
        }
    }

}
