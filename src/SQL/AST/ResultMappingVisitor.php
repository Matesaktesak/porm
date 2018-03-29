<?php

declare(strict_types=1);

namespace PORM\SQL\AST;

use PORM\Exceptions\InvalidQueryException;


class ResultMappingVisitor implements IVisitor {

    public function getNodeTypes() : array {
        return [
            Node\ResultField::class,
        ];
    }

    public function init() : void {

    }

    public function enter(Node\Node $node, Context $context) : void {

    }

    public function leave(Node\Node $node, Context $context) : void {
        /** @var Node\ResultField $node */
        $query = $context->getClosestQueryNode();

        if ($node->alias) {
            $field = $alias = $node->alias->value;
        } else {
            if ($node->value instanceof Node\Identifier) {
                $ident = $node->value;
            } else if ($node->value instanceof Node\UnaryExpression && $node->value->argument instanceof Node\Identifier) {
                $ident = $node->value->argument;
            } else {
                throw new InvalidQueryException("Missing alias for result field");
            }

            if (strpos($ident->value, '.') !== false) {
                list (, $field) = explode('.', $ident->value, 2);
            } else {
                $field = $ident->value;
            }

            if ($ident->hasMappingInfo()) {
                $mapping = $ident->getMappingInfo();
                $alias = $mapping['property'];
            } else {
                $alias = $field;
            }
        }

        $info = $node->value instanceof Node\Identifier && $node->value->hasTypeInfo() ? $node->value->getTypeInfo() : null;
        $query->mapResultField($field, $alias, $info['type'] ?? null, $info['nullable'] ?? null);
    }


}
