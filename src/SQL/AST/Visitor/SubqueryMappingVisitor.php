<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Visitor;

use PORM\SQL\AST\Context;
use PORM\SQL\AST\ILeaveVisitor;
use PORM\SQL\AST\Node;


class SubqueryMappingVisitor implements ILeaveVisitor {

    public function getNodeTypes() : array {
        return [
            Node\SubqueryExpression::class,
        ];
    }

    public function leave(Node\Node $node, Context $context) : void {
        /** @var Node\SubqueryExpression $node */
        /** @var Node\TableExpression|Node\JoinExpression $parent */
        if ($parent = $context->getParent(Node\TableExpression::class, Node\JoinExpression::class)) {
            $context->getClosestQueryNode()->mapResource($node->query->getResultFields(), $parent->alias->value ?? null);
        }
    }

}
