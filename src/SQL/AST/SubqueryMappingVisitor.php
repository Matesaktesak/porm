<?php

declare(strict_types=1);

namespace PORM\SQL\AST;


class SubqueryMappingVisitor implements IVisitor {

    public function getNodeTypes() : array {
        return [
            Node\SubqueryExpression::class
        ];
    }

    public function init() : void {

    }

    public function enter(Node\Node $node, Context $context) : void {

    }

    public function leave(Node\Node $node, Context $context) : void {
        /** @var Node\SubqueryExpression $node */
        /** @var Node\TableExpression|Node\JoinExpression $parent */
        $parent = $context->getParent(Node\TableExpression::class, Node\JoinExpression::class);

        if (!$parent) {
            return;
        }

        $context->getClosestQueryNode()->mapResource($node->query->getResultFields(), $parent->alias->value ?? null);
    }

}
