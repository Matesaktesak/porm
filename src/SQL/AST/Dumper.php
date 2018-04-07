<?php

declare(strict_types=1);

namespace PORM\SQL\AST;

use Nette\PhpGenerator;
use PORM\SQL\AST\Visitor\CallbackVisitor;


class Dumper {

    private const DUMP_PROPERTIES = [
        Node\ArithmeticExpression::class => ['operator'],
        Node\BinaryExpression::class => ['operator'],
        Node\FunctionCall::class => ['name'],
        Node\Identifier::class => ['value'],
        Node\Literal::class => ['type', 'value'],
        Node\LogicExpression::class => ['operator'],
        Node\NamedParameterReference::class => ['name'],
        Node\OrderExpression::class => ['ascending'],
        Node\UnaryExpression::class => ['operator'],
        Node\UnionClause::class => ['all'],
    ];

    private const DUMP_ATTRIBUTES = [
        Node\SelectQuery::class => ['tables', 'resultMap', 'parameterMap', 'parameters'],
        Node\InsertQuery::class => ['tables', 'resultMap', 'parameterMap', 'parameters'],
        Node\UpdateQuery::class => ['tables', 'resultMap', 'parameterMap', 'parameters'],
        Node\DeleteQuery::class => ['tables', 'resultMap', 'parameterMap', 'parameters'],
        Node\ParameterReference::class => ['type', 'nullable'],
        Node\NamedParameterReference::class => ['type', 'nullable'],
        Node\Identifier::class => ['entity', 'property', 'type', 'nullable'],
        Node\JoinExpression::class => ['relation'],
    ];


    public static function dump(Node\Node $node) : void {
        echo '<pre><code>';

        $walker = new Walker();
        $walker->apply($node, CallbackVisitor::forEnter(
            \Closure::fromCallable([static::class, 'dumpNode']),
            Node\ArithmeticExpression::class,
            Node\AssignmentExpression::class,
            Node\BinaryExpression::class,
            Node\CaseBranch::class,
            Node\CaseExpression::class,
            Node\DeleteQuery::class,
            Node\ExpressionList::class,
            Node\FunctionCall::class,
            Node\Identifier::class,
            Node\InsertQuery::class,
            Node\JoinExpression::class,
            Node\Literal::class,
            Node\LogicExpression::class,
            Node\NamedParameterReference::class,
            Node\OrderExpression::class,
            Node\ParameterReference::class,
            Node\ResultField::class,
            Node\SelectQuery::class,
            Node\SubqueryExpression::class,
            Node\TableExpression::class,
            Node\TableReference::class,
            Node\UnaryExpression::class,
            Node\UnionClause::class,
            Node\UpdateQuery::class,
            Node\ValuesExpression::class
        ));

        echo '</code></pre>';
    }


    private static function dumpNode(Node\Node $node, Context $context) : void {
        $type = $context->getNodeType();

        echo str_repeat('|  ', $context->getDepth());

        if ($prop = $context->getPropertyName()) {
            echo $prop . ': ';
        }

        echo substr($type, strlen(__NAMESPACE__ . '\\Node\\'));

        if (isset(self::DUMP_PROPERTIES[$type])) {
            echo ' (';

            echo implode(', ', array_map(function(string $prop) use ($node) : string {
                return $prop . ' = ' . self::dumpValue($node->$prop);
            }, self::DUMP_PROPERTIES[$type]));

            echo ')';
        }

        if (isset(self::DUMP_ATTRIBUTES[$type])) {
            echo ' [';

            echo implode(', ', array_map(function(string $attr) use ($node) : string {
                return $attr . ' = ' . self::dumpValue($node->attributes[$attr]);
            }, array_filter(self::DUMP_ATTRIBUTES[$type], function(string $attr) use ($node) : bool {
                return isset($node->attributes[$attr]) || key_exists($attr, $node->attributes);
            })));

            echo ']';
        }

        echo "\n";
    }

    private static function dumpValue($value) : string {
        static $usePhpGenerator = null;

        if (!isset($usePhpGenerator)) {
            $usePhpGenerator = class_exists(PhpGenerator\Helpers::class);
        }

        if ($usePhpGenerator) {
            return PhpGenerator\Helpers::dump($value);
        } else {
            return var_export($value, true);
        }
    }

}
