<?php

declare(strict_types=1);

namespace PORM\SQL\AST;

use Nette\Tokenizer\Exception;
use Nette\Tokenizer\Stream;
use Nette\Tokenizer\Token;
use Nette\Tokenizer\Tokenizer;
use PORM\Exceptions\ParseError;
use PORM\SQL\AST\Node\UnionClause;


class Parser {

    public const
        T_KEYWORD    = 0b000001,
        T_SYMBOL     = 0b000010,
        T_LITERAL    = 0b000100,
        T_IDENTIFIER = 0b001000,
        T_PARAMETER  = 0b010000,
        T_WHITESPACE = 0b100000;

    public const PATTERNS = [
        self::T_KEYWORD => 'SELECT|FROM|(?:(?:LEFT|INNER)\s+)?JOIN|UPDATE|SET|INSERT|INTO|VALUES|DELETE|EXISTS|' .
            'WHERE|GROUP\s+BY|HAVING|ORDER\s+BY|LIMIT|OFFSET|RETURNING|AS|CASE|WHEN|THEN|ELSE|END|AND|OR|IS|' .
            'LIKE|CONTAINS|(?:NOT\s+)?IN|NOT|DISTINCT|ASC|DESC|UNION(?:\s+ALL)?',
        self::T_SYMBOL => '[<>!]=|[-+/*%=<>(),*]',
        self::T_LITERAL => 'TRUE|FALSE|NULL|(?:[1-9]\d+|\d)(?:\.\d+)?|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"',
        self::T_IDENTIFIER => '[a-z_][a-z0-9_]*(?:(?::[a-z_][a-z0-9_]*)+|(?:\.[a-z_][a-z0-9_]*)+)?',
        self::T_PARAMETER => '\?|:[a-z][a-z0-9_]*',
        self::T_WHITESPACE => '\s++',
    ];

    public const TOKEN_NAMES = [
        self::T_KEYWORD => 'T_KEYWORD',
        self::T_SYMBOL => 'T_SYMBOL',
        self::T_LITERAL => 'T_LITERAL',
        self::T_IDENTIFIER => 'T_IDENTIFIER',
        self::T_PARAMETER => 'T_PARAMETER',
        self::T_WHITESPACE => 'T_WHITESPACE',
    ];


    private const NON_WS_TOKENS = [
        self::T_KEYWORD,
        self::T_SYMBOL,
        self::T_LITERAL,
        self::T_IDENTIFIER,
        self::T_PARAMETER,
    ];

    public const
        OP_ARITHMETIC = 0b0010,
        OP_BINARY     = 0b0100,
        OP_LOGIC      = 0b1000,
        OP_LOW_PRIO   = 0b0001;

    public const OPERATOR_MAP = [
        '*' => self::OP_ARITHMETIC,
        '/' => self::OP_ARITHMETIC,
        '%' => self::OP_ARITHMETIC,
        '+' => self::OP_ARITHMETIC | self::OP_LOW_PRIO,
        '-' => self::OP_ARITHMETIC | self::OP_LOW_PRIO,
        '>' => self::OP_BINARY,
        '>=' => self::OP_BINARY,
        '=' => self::OP_BINARY,
        '!=' => self::OP_BINARY,
        '<=' => self::OP_BINARY,
        '<' => self::OP_BINARY,
        'LIKE' => self::OP_BINARY,
        'CONTAINS' => self::OP_BINARY,
        'IS' => self::OP_BINARY,
        'IN' => self::OP_BINARY,
        'NOT IN' => self::OP_BINARY,
        'OR' => self::OP_LOGIC | self::OP_LOW_PRIO,
        'AND' => self::OP_LOGIC,
    ];

    private const EXPR_MAP = [
        self::OP_ARITHMETIC => Node\ArithmeticExpression::class,
        self::OP_BINARY => Node\BinaryExpression::class,
        self::OP_LOGIC => Node\LogicExpression::class,
    ];


    public function parseQuery(string $query) : Node\Query {
        $stream = $this->tokenize($query);
        $first = $stream->nextToken('SELECT', 'INSERT', 'UPDATE', 'DELETE');
        $stream->reset();

        switch ($first->value ?? null) {
            case 'SELECT': $query = $this->parseSelectQuery($stream, ';'); break;
            case 'INSERT': $query = $this->parseInsertQuery($stream); break;
            case 'UPDATE': $query = $this->parseUpdateQuery($stream); break;
            case 'DELETE': $query = $this->parseDeleteQuery($stream); break;
        }

        if ($stream->isNext(self::NON_WS_TOKENS)) {
            throw $this->parseError($stream->nextToken());
        }

        return $query;
    }

    public function parseExpression(string $expression) : Node\Expression {
        $stream = $this->tokenize($expression);

        if ($stream->isNext('(')) {
            $expr = $this->parseStmt($stream, true);
        } else {
            $expr = $this->parseExpr($stream, true);
        }

        if ($stream->isNext(self::NON_WS_TOKENS)) {
            throw $this->parseError($stream->nextToken());
        }

        return $expr;
    }

    private function parseSelectQuery(Stream $stream, ... $until) : Node\SelectQuery {
        do {
            $this->consume($stream, true, 'SELECT');
            $query = new Node\SelectQuery();
            $query->unionWith = $unionWith ?? null;
            $query->fields = $this->parseResultFields($stream);
            $query->from = $this->parseFromClause($stream);

            if ($stream->nextToken('WHERE')) {
                $query->where = $this->parseExpr($stream, true);
            }

            if ($stream->nextToken('GROUP BY')) {
                do {
                    if ($token = $stream->nextToken(self::T_IDENTIFIER)) {
                        $query->groupBy[] = new Node\Identifier($token->value);
                    } else {
                        throw $this->parseError($stream->nextToken());
                    }
                } while ($stream->nextToken(','));
            }

            if ($stream->nextToken('HAVING')) {
                $query->having = $this->parseExpr($stream, true);
            }

            $this->parseCommonClauses($stream, $query);

            if ($token = $stream->nextToken('UNION', 'UNION ALL')) {
                $unionWith = new UnionClause($query, $token->value === 'UNION ALL');
            } else {
                $unionWith = null;
            }
        } while ($unionWith || $stream->isNext(... self::NON_WS_TOKENS) && (!$until || !$stream->isNext(... $until)));

        return $query;
    }

    private function parseInsertQuery(Stream $stream) : Node\InsertQuery {
        $this->consume($stream, true, 'INSERT', 'INTO');
        $query = new Node\InsertQuery();

        if ($token = $stream->nextToken(self::T_IDENTIFIER)) {
            $query->into = new Node\TableReference($token->value);
        } else {
            throw $this->parseError($stream->nextToken());
        }

        if ($stream->nextToken('(')) {
            $query->fields = $this->parseColumnList($stream);
            $this->consume($stream, true, ')');
        }

        if ($stream->isNext('SELECT')) {
            $query->dataSource = $this->parseSelectQuery($stream, ';');
        } else {
            $query->dataSource = $this->parseValuesExpression($stream);
        }

        if ($stream->nextToken('RETURNING')) {
            $query->returning = $this->parseResultFields($stream);
        }

        return $query;
    }

    private function parseUpdateQuery(Stream $stream) : Node\UpdateQuery {
        $this->consume($stream, true, 'UPDATE');
        $query = new Node\UpdateQuery();
        $query->table = $this->parseTableExpression($stream, true, true);
        $this->consume($stream, true, 'SET');
        $query->data = $this->parseAssignmentList($stream);

        if ($stream->nextToken('WHERE')) {
            $query->where = $this->parseExpr($stream, true);
        }

        $this->parseCommonClauses($stream, $query);

        if ($stream->nextToken('RETURNING')) {
            $query->returning = $this->parseResultFields($stream);
        }

        return $query;
    }

    private function parseDeleteQuery(Stream $stream) : Node\DeleteQuery {
        $this->consume($stream, true, 'DELETE', 'FROM');
        $query = new Node\DeleteQuery();
        $query->from = $this->parseTableExpression($stream, true, true);

        if ($stream->nextToken('WHERE')) {
            $query->where = $this->parseExpr($stream, true);
        }

        $this->parseCommonClauses($stream, $query);

        if ($stream->nextToken('RETURNING')) {
            $query->returning = $this->parseResultFields($stream);
        }

        return $query;
    }


    private function parseResultFields(Stream $stream) : array {
        $fields = [];

        while ($field = $this->parseResultField($stream)) {
            $fields[] = $field;
        }

        if (!$fields) {
            throw $this->parseError($stream->nextToken());
        }

        return $fields;
    }


    private function parseResultField(Stream $stream) : ?Node\ResultField {
        if ($value = $this->parseExpr($stream)) {
            $field = new Node\ResultField($value);
            $field->alias = $this->parseAlias($stream);
            $stream->nextToken(',');
            return $field;
        } else {
            return null;
        }
    }


    /**
     * @param Stream $stream
     * @param bool $single
     * @return Node\TableExpression|Node\TableExpression[]
     */
    private function parseFromClause(Stream $stream, bool $single = false) {
        $from = $single ? null : [];

        if (!$this->consume($stream, $single, 'FROM')) {
            return $from;
        }

        $first = true;

        while ($tbl = $this->parseTableExpression($stream, $first)) {
            if ($single) {
                return $tbl;
            }

            $from[] = $tbl;
            $first = false;
        }

        return $from;
    }


    private function parseTableExpression(Stream $stream, bool $need = false, bool $referenceOnly = false) : ?Node\TableExpression {
        if (!$referenceOnly && ($token = $stream->nextToken('JOIN', 'LEFT JOIN', 'INNER JOIN'))) {
            $expr = new Node\JoinExpression(
                $this->parseTable($stream, true),
                substr($token->value, 0, -5) ?: 'LEFT'
            );
        } else if ($tbl = $this->parseTable($stream, false, $referenceOnly)) {
            $expr = new Node\TableExpression($tbl);
        } else if ($need) {
            throw $this->parseError($stream->nextToken());
        } else {
            return null;
        }

        $expr->alias = $this->parseAlias($stream);

        if ($expr instanceof Node\JoinExpression && $stream->nextToken('ON')) {
            $expr->condition = $this->parseExpr($stream, true);
        } else {
            $stream->nextToken(',');
        }

        return $expr;
    }

    private function parseTable(Stream $stream, bool $need = false, bool $referenceOnly = false) : ?Node\ITable {
        if ($token = $stream->nextToken(self::T_IDENTIFIER)) {
            return new Node\TableReference($token->value);
        } else if (!$referenceOnly && $stream->nextToken('(')) {
            $expr = new Node\SubqueryExpression($this->parseSelectQuery($stream, ')'));
            $this->consume($stream, true, ')');
            return $expr;
        } else if ($need) {
            throw $this->parseError($stream->nextToken());
        } else {
            return null;
        }
    }

    private function parseAlias(Stream $stream) : ?Node\Identifier {
        $as = $stream->nextToken('AS');

        if ($token = $stream->nextToken(self::T_IDENTIFIER)) {
            return new Node\Identifier($token->value);
        } else if ($as) {
            throw $this->parseError($stream->nextToken());
        } else {
            return null;
        }
    }

    private function parseIdentifier(Stream $stream) : Node\Identifier {
        if ($token = $stream->nextToken(self::T_IDENTIFIER)) {
            return new Node\Identifier($token->value);
        } else {
            throw $this->parseError($stream->nextToken());
        }
    }

    private function parseColumnList(Stream $stream) : array {
        $columns = [];

        do {
            $columns[] = $this->parseIdentifier($stream);
        } while ($stream->nextToken(','));

        return $columns;
    }

    private function parseAssignmentList(Stream $stream) : array {
        $data = [];

        do {
            $target = $this->parseIdentifier($stream);
            $this->consume($stream, true, '=');
            $value = $this->parseExpr($stream, true);
            $data[] = new Node\AssignmentExpression($target, $value);
        } while ($stream->nextToken(','));

        return $data;
    }


    /**
     * @param Stream $stream
     * @param Node\SelectQuery|Node\UpdateQuery|Node\DeleteQuery $query
     */
    private function parseCommonClauses(Stream $stream, $query) : void {
        if ($stream->nextToken('ORDER BY')) {
            do {
                $query->orderBy[] = new Node\OrderExpression(
                    $this->parseExpr($stream, true),
                    $stream->nextValue('ASC', 'DESC') !== 'DESC'
                );
            } while ($stream->nextToken(','));
        }

        if ($stream->nextToken('LIMIT')) {
            $query->limit = $this->parseExpr($stream, true);
        }

        if ($stream->nextToken('OFFSET')) {
            $query->offset = $this->parseExpr($stream, true);
        }
    }

    private function parseValuesExpression(Stream $stream) : Node\ValuesExpression {
        $this->consume($stream, true, 'VALUES');
        $expr = new Node\ValuesExpression();

        do {
            $this->consume($stream, true, '(');
            $expr->dataSets[] = $this->parseExpressionList($stream, true);
            $this->consume($stream, true, ')');
        } while ($stream->nextToken(','));

        return $expr;
    }

    private function parseExpressionList(Stream $stream, bool $need = false, bool $allowEmpty = false) : ?Node\ExpressionList {
        $expressions = [];

        while ($val = $this->parseExpr($stream)) {
            $expressions[] = $val;
            $stream->nextToken(',');
        }

        if (!$expressions) {
            if ($allowEmpty) {
                return new Node\ExpressionList();
            } else if ($need) {
                throw $this->parseError($stream->nextToken());
            } else {
                return null;
            }
        } else {
            return new Node\ExpressionList(... $expressions);
        }
    }


    private function parseExpr(Stream $stream, bool $need = false) : ?Node\Expression {
        $buffer = [];
        $op = true;
        $n = 0;

        do {
            if ($op) {
                $val = $this->parseStmt($stream, false, $op === 'IN' || $op === 'NOT IN');
                $op = null;
            } else if ($token = $stream->nextToken('+', '-', '*', '/', '%', '>', '>=', '=', '!=', '<=', '<', 'IS', 'LIKE', 'CONTAINS', 'IN', 'NOT IN', 'AND', 'OR')) {
                $val = [self::OPERATOR_MAP[$token->value], $token->value];
                $op = $token->value;
            } else {
                $val = null;
            }

            if ($val) {
                $buffer[] = $val;
            } else {
                break;
            }

            $n++;
        } while ($stream->isNext(... self::NON_WS_TOKENS));

        if (!$n) {
            if ($need) {
                throw $this->parseError($stream->nextToken());
            } else {
                return null;
            }
        } else if ($n % 2 === 0) {
            throw $this->parseError($stream->nextToken());
        }

        foreach (self::EXPR_MAP as $op => $class) {
            for ($i = 1; $i < $n; $i += 2) {
                if (($buffer[$i][0] & ~self::OP_LOW_PRIO) === $op) {
                    $expr = new $class($buffer[$i - 1], $buffer[$i][1], $buffer[$i + 1]);
                    array_splice($buffer, $i - 1, 3, [$expr]);
                    $i -= 2;
                    $n -= 2;
                }
            }
        }

        return reset($buffer);
    }

    private function parseStmt(Stream $stream, bool $need = false, bool $list = false) : ?Node\Expression {
        switch (true) {
            case (bool) $stream->nextToken('CASE'):
                return $this->parseCaseExpression($stream);

            case (bool) ($token = $stream->nextToken('NOT')):
            case (bool) ($token = $stream->nextToken('DISTINCT')):
                return new Node\UnaryExpression(
                    $token->value,
                    $this->parseStmt($stream)
                );

            case (bool) $stream->nextToken('('):
                if ($stream->isNext('SELECT')) {
                    $value = new Node\SubqueryExpression($this->parseSelectQuery($stream, ')'));
                } else if ($list) {
                    $value = $this->parseExpressionList($stream, false, true);
                } else {
                    $value = $this->parseExpr($stream);
                }

                $this->consume($stream, true, ')');
                return $value;

            case (bool) $stream->nextToken('*'):
                return new Node\Identifier('*');

            case (bool) ($token = $stream->nextToken(self::T_LITERAL)):
                switch (ucfirst($token->value[0])) {
                    case 'T': return Node\Literal::bool(true);
                    case 'F': return Node\Literal::bool(false);
                    case 'N': return Node\Literal::null();

                    case '"':
                    case "'":
                        return Node\Literal::string(strtr(substr($token->value, 1, -1), ['\\"' => '"', "\\'" => "'", '\\\\' => '\\']));

                    default:
                        if (strpos($token->value, '.') !== false) {
                            return Node\Literal::float((float) $token->value);
                        } else {
                            return Node\Literal::int((int) $token->value);
                        }
                }

            case (bool) ($token = $stream->nextToken(self::T_IDENTIFIER)):
                if ($stream->isNext('(')) {
                    return $this->parseFunctionCall($token->value, $stream);
                } else {
                    return new Node\Identifier($token->value);
                }

            case (bool) ($token = $stream->nextToken(self::T_PARAMETER)):
                if ($token->value === '?') {
                    return new Node\ParameterReference();
                } else {
                    return new Node\NamedParameterReference(substr($token->value, 1));
                }

            default:
                if ($need) {
                    throw $this->parseError($stream->nextToken());
                } else {
                    return null;
                }
        }
    }



    private function parseFunctionCall(string $name, Stream $stream) : Node\FunctionCall {
        $this->consume($stream, true, '(');
        $func = new Node\FunctionCall($name);
        $func->arguments = $this->parseExpressionList($stream, false, true);
        $this->consume($stream, true, ')');
        return $func;
    }

    private function parseCaseExpression(Stream $stream) : Node\CaseExpression {
        $expr = new Node\CaseExpression();
        $first = true;

        while ($branch = $this->parseCaseBranch($stream, $first)) {
            $expr->branches[] = $branch;
            $first = false;
        }

        if ($stream->nextToken('ELSE')) {
            $expr->else = $this->parseExpr($stream, true);
        }

        $this->consume($stream, true, 'END');
        return $expr;
    }

    private function parseCaseBranch(Stream $stream, bool $need = false) : ?Node\CaseBranch {
        if (!$this->consume($stream, $need, 'WHEN')) {
            return null;
        }

        $branch = new Node\CaseBranch();
        $branch->condition = $this->parseExpr($stream, true);
        $this->consume($stream, true, 'THEN');
        $branch->statement = $this->parseExpr($stream, true);

        return $branch;
    }


    private function consume(Stream $stream, bool $need = true, ... $tokens) : bool {
        foreach ($tokens as $token) {
            if (!$stream->nextToken($token)) {
                if ($need) {
                    throw $this->parseError($stream->nextToken());
                } else {
                    return false;
                }
            }
        }

        return true;
    }


    private function tokenize(string $query) : Stream {
        $tokenizer = new Tokenizer(self::PATTERNS, 'i');

        try {
            $stream = $tokenizer->tokenize($query);
            $stream->ignored[] = self::T_WHITESPACE;

            foreach ($stream->tokens as $token) {
                if ($token->type === self::T_KEYWORD) {
                    $token->value = strtoupper(preg_replace('/\s+/', ' ', $token->value));
                }
            }

            return $stream;
        } catch (Exception $e) {
            throw new ParseError($e->getMessage(), 0, $e);
        }
    }

    private function parseError(?Token $token = null) : ParseError {
        return new ParseError('Unexpected ' . ($token ? sprintf('%s "%s" at offset %d', self::TOKEN_NAMES[$token->type], $token->value, $token->offset) : 'end of query'));
    }

}
