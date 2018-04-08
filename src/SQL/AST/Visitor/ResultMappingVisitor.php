<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Visitor;

use PORM\Exceptions\InvalidQueryException;
use PORM\SQL\AST\Context;
use PORM\SQL\AST\IEnterVisitor;
use PORM\SQL\AST\ILeaveVisitor;
use PORM\SQL\AST\Node;


class ResultMappingVisitor implements IEnterVisitor, ILeaveVisitor {

    public function getNodeTypes() : array {
        return [
            Node\SelectQuery::class,
            Node\InsertQuery::class,
            Node\UpdateQuery::class,
            Node\DeleteQuery::class,
        ];
    }

    public function enter(Node\Node $node, Context $context) : void {
        /** @var Node\Query $node */
        $src = $context->getNodeType() === Node\SelectQuery::class ? 'fields' : 'returning';

        for ($i = 0, $n = count($node->$src); $i < $n; $i++) {
            /** @var Node\ResultField $field */
            $field = $node->$src[$i];

            if ($field->value instanceof Node\Identifier && preg_match('~^([^.]+)\.\*$~', $field->value->value, $m)) {
                if ($node->hasMappedResource($m[1])) {
                    $fields = $node->getMappedResourceFields($m[1]);
                    $tmp = [];
                    $c = -1;

                    foreach ($fields as $prop => $info) {
                        $tmp[] = new Node\ResultField(new Node\Identifier($m[1] . '.' . $prop));
                        $c++;
                    }

                    array_splice($node->$src, $i, 1, $tmp);
                    $i += $c;
                    $n += $c;
                } else {
                    throw new InvalidQueryException("Unknown alias '{$m[1]}' in expression '{$field->value->value}'");
                }
            }
        }
    }

    public function leave(Node\Node $node, Context $context) : void {
        /** @var Node\Query $node */
        $src = $context->getNodeType() === Node\SelectQuery::class ? 'fields' : 'returning';

        foreach ($node->$src as $field) { /** @var Node\ResultField $field */
            if ($field->alias) {
                $name = $alias = $field->alias->value;
            } else {
                if ($field->value instanceof Node\Identifier) {
                    $ident = $field->value;
                } else if ($field->value instanceof Node\UnaryExpression && $field->value->argument instanceof Node\Identifier) {
                    $ident = $field->value->argument;
                } else {
                    throw new InvalidQueryException("Missing alias for result field");
                }

                if (strpos($ident->value, '.') !== false) {
                    list (, $name) = explode('.', $ident->value, 2);
                } else {
                    $name = $ident->value;
                }

                if ($ident->hasMappingInfo()) {
                    $mapping = $ident->getMappingInfo();
                    $alias = $mapping['property'];
                } else {
                    $alias = $name;
                }
            }

            $info = $field->value instanceof Node\Identifier && $field->value->hasTypeInfo() ? $field->value->getTypeInfo() : null;
            $node->mapResultField($name, $alias, $info['type'] ?? null, $info['nullable'] ?? null);
        }
    }


}
