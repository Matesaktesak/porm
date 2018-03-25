<?php

declare(strict_types=1);

namespace PORM\SQL\AST;

use PORM\SQL\InvalidQueryException;


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

        if ($node->value instanceof Node\Identifier) {
            if (strpos($node->value->value, '.') !== false) {
                list (, $field) = explode('.', $node->value->value, 2);
            } else {
                $field = $node->value->value;
            }

            if ($node->alias) {
                $alias = $node->alias->value;
            } else if ($node->value->hasMappingInfo()) {
                $mapping = $node->value->getMappingInfo();
                $alias = $mapping['property'];
            } else {
                $alias = $field;
            }

            $info = $node->value->hasTypeInfo() ? $node->value->getTypeInfo() : null;
        } else if ($node->alias) {
            $field = $alias = $node->alias->value;
        } else {
            throw new InvalidQueryException("Missing alias for result field");
        }

        $query->mapResultField($field, $alias, $info['type'] ?? null, $info['nullable'] ?? null);
    }


}
