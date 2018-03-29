<?php

declare(strict_types=1);

namespace PORM\Drivers\Firebird;

use PORM\Exceptions\DriverException;
use PORM\Drivers\IDriver;
use PORM\Drivers\IPlatform;
use PORM\Migrations\Migration;
use PORM\SQL\AST\Node as AST;
use PORM\SQL\AST\Parser;
use PORM\SQL\Expression;
use PORM\Exceptions\InvalidQueryException;


class Platform implements IPlatform {

    private const MIGRATION_TABLE = 'PORM_MIGRATIONS';


    private $stack = [];


    public function supportsReturningClause() : bool {
        return true;
    }

    public function formatGenerator(string $name, bool $increment = true) : Expression {
        return new Expression('GEN_ID(' . $name . ', ?)', [$increment ? 1 : 0]);
    }

    public function formatSelectQuery(AST\SelectQuery $query) : string {
        $this->stack[] = null;
        $sql = [];

        if ($query->unionWith) {
            $sql[] = $this->formatSelectQuery($query->unionWith->query);
            $sql[] = $query->unionWith->all ? 'UNION ALL' : 'UNION';
        }

        $sql[] = 'SELECT';
        $sql[] = implode(', ', array_map(\Closure::fromCallable([$this, 'formatASTResultField']), $query->fields));
        $sql[] = 'FROM';

        if ($query->from) {
            $first = true;

            foreach ($query->from as $table) {
                if (!$first && !($table instanceof AST\JoinExpression)) {
                    $sql[] = ',';
                }

                $sql[] = $this->formatASTTableExpression($table);
                $first = false;
            }
        } else {
            $sql[] = 'RDB$DATABASE';
        }

        $this->applyWhereClause($sql, $query->where);

        if ($query->groupBy) {
            $sql[] = 'GROUP BY';
            $sql[] = implode(', ', array_map(\Closure::fromCallable([$this, 'formatASTIdentifier']), $query->groupBy));
        }

        if ($query->having) {
            $sql[] = 'HAVING';
            $sql[] = $this->formatASTExpression($query->having);
        }

        if ($query->orderBy) {
            $sql[] = 'ORDER BY';
            $sql[] = implode(', ', array_map(\Closure::fromCallable([$this, 'formatASTOrderExpression']), $query->orderBy));
        }

        if ($query->offset) {
            $sql[] = sprintf('OFFSET %d %s', $query->offset->value, $query->offset->value === 1 ? 'ROW' : 'ROWS');
        }

        if ($query->limit) {
            $sql[] = sprintf('FETCH %s %d %s ONLY', $query->offset ? 'NEXT' : 'FIRST', $query->limit->value, $query->limit->value === 1 ? 'ROW' : 'ROWS');
        }

        array_pop($this->stack);
        return implode(' ', $sql);
    }

    public function formatInsertQuery(AST\InsertQuery $query) : string {
        $this->stack[] = null;

        $sql = [
            'INSERT INTO',
            $this->formatASTTable($query->into),
        ];

        if ($query->fields) {
            $sql[] = '(' . implode(', ', array_map(\Closure::fromCallable([$this, 'formatASTIdentifier']), $query->fields)) . ')';
        }

        if ($query->dataSource instanceof AST\ValuesExpression) {
            $sql[] = 'VALUES';
            $sql[] = implode(', ', array_map(\Closure::fromCallable([$this, 'formatASTExpressionList']), $query->dataSource->dataSets));
        } else if ($query->dataSource instanceof AST\SelectQuery) {
            $sql[] = $this->formatSelectQuery($query->dataSource);
        }

        $this->applyReturningClause($sql, $query->returning);

        array_pop($this->stack);
        return implode(' ', $sql);
    }

    public function formatUpdateQuery(AST\UpdateQuery $query) : string {
        $this->stack[] = null;

        $sql = [
            'UPDATE',
            $this->formatASTTableExpression($query->table),
            'SET',
            implode(', ', array_map(\Closure::fromCallable([$this, 'formatASTAssignmentExpression']), $query->data)),
        ];

        $this->applyWhereClause($sql, $query->where);
        $this->applyCommonClauses($sql, $query->orderBy, $query->offset, $query->limit);
        $this->applyReturningClause($sql, $query->returning);

        array_pop($this->stack);
        return implode(' ', $sql);
    }

    public function formatDeleteQuery(AST\DeleteQuery $query) : string {
        $this->stack[] = null;

        $sql = [
            'DELETE FROM',
            $this->formatASTTableExpression($query->from),
        ];

        $this->applyWhereClause($sql, $query->where);
        $this->applyCommonClauses($sql, $query->orderBy, $query->offset, $query->limit);
        $this->applyReturningClause($sql, $query->returning);

        array_pop($this->stack);
        return implode(' ', $sql);
    }


    public function toPlatformBool(bool $value) {
        return (int) $value;
    }

    public function toPlatformDate(\DateTimeInterface $date) : string {
        return $date->format('Y-m-d');
    }

    public function toPlatformTime(\DateTimeInterface $time) : string {
        return $time->format('H:i:s');
    }

    public function toPlatformDateTime(\DateTimeInterface $datetime) : string {
        return $datetime->format('Y-m-d H:i:s');
    }

    public function fromPlatformBool($value) : bool {
        return !empty($value);
    }

    public function fromPlatformDate(string $date) : \DateTimeImmutable {
        return new \DateTimeImmutable($date);
    }

    public function fromPlatformTime(string $time) : \DateTimeImmutable {
        return new \DateTimeImmutable($time);
    }

    public function fromPlatformDateTime(string $datetime) : \DateTimeImmutable {
        return new \DateTimeImmutable($datetime);
    }


    public function getAppliedMigrations(IDriver $driver) : array {
        if (!$this->doesMigrationTableExist($driver)) {
            return [];
        }

        $result = $driver->query(
            'SELECT "version", "type" FROM ' . $this->escapeIdentifier(self::MIGRATION_TABLE) . ' ORDER BY "version"'
        );

        return array_map(function(array $row) : Migration {
            return new Migration($row['version'], $row['type']);
        }, iterator_to_array($result));
    }

    public function markMigrationApplied(IDriver $driver, Migration $migration) : void {
        if (!$this->doesMigrationTableExist($driver)) {
            $driver->query(
                'CREATE TABLE ' . $this->escapeIdentifier(self::MIGRATION_TABLE) . ' (' .
                    '"version" BIGINT NOT NULL PRIMARY KEY, ' .
                    '"type" VARCHAR(3) NOT NULL' .
                ')'
            );

            $driver->query('COMMIT');
        }

        $driver->query(
            'INSERT INTO ' . $this->escapeIdentifier(self::MIGRATION_TABLE) . ' ("version", "type") ' .
            'VALUES (?, ?)',
            [
                $migration->getVersion(),
                $migration->getType(),
            ]
        );
    }


    private function doesMigrationTableExist(IDriver $driver) : bool {
        return (bool) $driver->query('SELECT 1 FROM RDB$RELATIONS WHERE RDB$RELATION_NAME = ?', [self::MIGRATION_TABLE])
            ->fetchSingle();
    }


    private function applyWhereClause(array & $query, ?AST\Expression $where) : void {
        if ($where) {
            $query[] = 'WHERE';
            $query[] = $this->formatASTExpression($where);
        }
    }

    private function applyCommonClauses(array & $query, ?array $orderBy, ?AST\Literal $offset, ?AST\Literal $limit) : void {
        if ($orderBy) {
            $query[] = 'ORDER BY';
            $query[] = implode(', ', array_map(\Closure::fromCallable([$this, 'formatASTOrderExpression']), $orderBy));
        }

        if ($offset) {
            $query[] = sprintf('ROWS %d', $offset->value + 1);
        }

        if ($limit) {
            $query[] = sprintf('%s %d', $offset ? 'TO' : 'ROWS', $offset ? $offset->value + $limit->value : $limit->value);
        }
    }

    private function applyReturningClause(array & $query, ?array $returning) : void {
        if ($returning) {
            $query[] = 'RETURNING';
            $query[] = implode(', ', array_map(\Closure::fromCallable([$this, 'formatASTResultField']), $returning));
        }
    }





    private function formatASTExpression(AST\Expression $expression) : string {
        switch (get_class($expression)) {
            case AST\Identifier::class: /** @var AST\Identifier $expression */
                return $this->formatASTIdentifier($expression);

            case AST\Literal::class: /** @var AST\Literal $expression */
                return $this->formatASTLiteral($expression);

            case AST\ParameterReference::class:
                return '?';

            case AST\FunctionCall::class: /** @var AST\FunctionCall $expression */
                $this->stack[] = null;
                $expression = $this->formatASTFunctionCall($expression);
                break;

            case AST\ExpressionList::class: /** @var AST\ExpressionList $expression */
                $this->stack[] = null;
                $expression = $this->formatASTExpressionList($expression);
                break;

            case AST\SubqueryExpression::class: /** @var AST\SubqueryExpression $expression */
                return '(' . $this->formatSelectQuery($expression->query) . ')';

            case AST\CaseExpression::class: /** @var AST\CaseExpression $expression */
                $this->stack[] = null;
                $expression = $this->formatASTCaseExpression($expression);
                break;

            case AST\UnaryExpression::class: /** @var AST\UnaryExpression $expression */
                $this->stack[] = -1;
                $expression = $expression->operator . ' ' . $this->formatASTExpression($expression->argument);
                break;

            case AST\ArithmeticExpression::class: /** @var AST\ArithmeticExpression $expression */
            case AST\BinaryExpression::class: /** @var AST\BinaryExpression $expression */
            case AST\LogicExpression::class: /** @var AST\LogicExpression $expression */
                $parent = end($this->stack);
                [$op, $cp] = $parent && Parser::OPERATOR_MAP[$expression->operator] > $parent ? ['(', ')'] : ['', ''];
                $this->stack[] = Parser::OPERATOR_MAP[$expression->operator];

                if ($expression->operator === 'CONTAINS') {
                    $operator = 'CONTAINING';
                } else {
                    $operator = $expression->operator;

                    if (($operator === 'IN' || $operator === 'NOT IN') && !($expression->right instanceof AST\ExpressionList || $expression->right instanceof AST\SubqueryExpression)) {
                        $expression->right = new AST\ExpressionList($expression->right);
                    }
                }

                $expression = sprintf('%s%s %s %s%s',
                    $op,
                    $this->formatASTExpression($expression->left),
                    $operator,
                    $this->formatASTExpression($expression->right),
                    $cp
                );
                break;

            default:
                throw new DriverException("Unknown expression type " . get_class($expression));
        }

        array_pop($this->stack);
        return $expression;
    }

    private function formatASTIdentifier(AST\Identifier $identifier) : string {
        return $this->escapeIdentifier($identifier->value);
    }

    private function escapeIdentifier(string $identifier) : string {
        if ($identifier === '*') {
            return '*';
        } else {
            preg_match('~^(.*?)(\.\*)?$~', $identifier, $m);
            return '"' . strtr($m[1], ['"' => '""', '.' => '"."']) . '"' . ($m[2] ?? '');
        }
    }

    private function formatASTLiteral(AST\Literal $literal) : string {
        switch ($literal->type) {
            case 'int':
            case 'float':
                return (string) $literal->value;
            case 'bool':
                return (string) (int) $literal->value;
            case 'null':
                return 'NULL';
            case 'string':
                return "'" . str_replace("'", "''", $literal->value) . "'";
            default:
                throw new DriverException("Invalid literal type: '{$literal->type}'");
        }
    }

    private function formatASTFunctionCall(AST\FunctionCall $call) : string {
        $ucName = strtoupper($call->name);

        if (method_exists(NativeFunctions::class, $ucName)) {
            try {
                [$expr, $args] = call_user_func_array([NativeFunctions::class, $ucName], $call->arguments->expressions);

                if ($args) {
                    $expr = vsprintf($expr, array_map(function($v) {
                        return $v instanceof AST\Expression ? $this->formatASTExpression($v) : $v;
                    }, $args));
                }

                return $expr;
            } catch (\TypeError $e) {
                $ns = preg_quote(substr(AST\Node::class, 0, -4));
                $pattern = '~argument (\d+) passed to .+? must be (?:of the type|an instance of) (?:' . $ns . ')?(\S+), (?:instance of )?(?:' . $ns . ')?(\S+) given~i';

                if (preg_match($pattern, $e->getMessage(), $m)) {
                    throw new InvalidQueryException("Invalid argument #{$m[1]} passed when calling {$call->name}(), expected {$m[2]}, got {$m[1]}");
                } else {
                    throw new InvalidQueryException("Invalid argument passed when calling {$call->name}()");
                }
            } catch (\ArgumentCountError $e) {
                throw new InvalidQueryException("Invalid call to {$call->name}(), too few arguments");
            }
        }

        return $ucName . ($call->arguments ? $this->formatASTExpressionList($call->arguments) : '()');
    }


    private function formatASTExpressionList(AST\ExpressionList $list) : string {
        return '(' . implode(', ', array_map(\Closure::fromCallable([$this, 'formatASTExpression']), $list->expressions)) . ')';
    }

    private function formatASTCaseExpression(AST\CaseExpression $expr) : string {
        return 'CASE '
            . implode(' ', array_map(\Closure::fromCallable([$this, 'formatASTCaseBranch']), $expr->branches))
            . ($expr->else ? ' ELSE ' . $this->formatASTExpression($expr->else) : '')
            . ' END';
    }

    private function formatASTCaseBranch(AST\CaseBranch $expr) : string {
        return 'WHEN ' . $this->formatASTExpression($expr->condition) . ' THEN ' . $this->formatASTExpression($expr->statement);
    }

    private function formatASTResultField(AST\ResultField $field) : string {
        $expr = $this->formatASTExpression($field->value);

        if ($field->alias) {
            $expr .= ' AS ' . $this->formatASTIdentifier($field->alias);
        }

        return $expr;
    }

    private function formatASTTableExpression(AST\TableExpression $expr) : string {
        $join = $expr instanceof AST\JoinExpression; /** @var AST\JoinExpression $expr */
        $table = $join ? $expr->type . ' JOIN ' : '';
        $table .= $this->formatASTTable($expr->table);

        if ($expr->alias) {
            $table .= ' AS ' . $this->formatASTIdentifier($expr->alias);
        }

        if ($join && $expr->condition) {
            $table .= ' ON ' . $this->formatASTExpression($expr->condition);
        }

        return $table;
    }

    private function formatASTTable(AST\ITable $table) : string {
        switch (get_class($table)) {
            case AST\TableReference::class: /** @var AST\TableReference $table */
                return $this->formatASTIdentifier($table->name);

            case AST\SubqueryExpression::class: /** @var AST\SubqueryExpression $table */
                $this->stack[] = null;
                $expression = '(' . $this->formatSelectQuery($table->query) . ')';
                array_pop($this->stack);
                return $expression;

            default:
                throw new DriverException('Unknown AST node ' . get_class($table));
        }
    }

    private function formatASTAssignmentExpression(AST\AssignmentExpression $expr) : string {
        return $this->formatASTIdentifier($expr->target) . ' = ' . $this->formatASTExpression($expr->value);
    }

    private function formatASTOrderExpression(AST\OrderExpression $expr) : string {
        return $this->formatASTExpression($expr->value) . ($expr->ascending ? ' ASC' : ' DESC');
    }

}
