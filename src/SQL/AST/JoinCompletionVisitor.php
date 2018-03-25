<?php

declare(strict_types=1);

namespace PORM\SQL\AST;

use PORM\Metadata\Registry;
use PORM\SQL\InvalidQueryException;


class JoinCompletionVisitor implements IVisitor {

    private $metadataRegistry;


    public function __construct(Registry $registry) {
        $this->metadataRegistry = $registry;
    }


    public function getNodeTypes() : array {
        return [
            Node\JoinExpression::class,
        ];
    }

    public function init() : void {

    }

    public function enter(Node\Node $node, Context $context) : void {
        /** @var Node\JoinExpression $node */
        $query = $context->getClosestQueryNode();

        if (!$node->condition && $node->isRelation()) {
            $relation = $node->getRelationInfo();
            $meta1 = $this->metadataRegistry->get($query->getMappedEntity($relation['from']));
            $info1 = $meta1->getRelationInfo($relation['property']);
            $meta2 = $this->metadataRegistry->get($info1['target']);
            $alias = $node->alias ? $node->alias->value : $meta2->getEntityClass();

            if (isset($info1['fk'])) {
                $id2 = $meta2->getSingleIdentifierProperty();

                if (!$id2) {
                    throw new InvalidQueryException("Failed to complete join condition to entity with " . ($meta2->getIdentifierProperties() ? 'complex' : 'no') . " identifier");
                }

                $node->condition = new Node\BinaryExpression(
                    new Node\Identifier($alias . '.' . $id2),
                    '=',
                    new Node\Identifier($relation['from'] . '.' . $info1['fk'])
                );
            } else if ($meta2->hasRelationTarget($meta1->getEntityClass(), $relation['property'])) {
                $target = $meta2->getRelationTarget($meta1->getEntityClass(), $relation['property']);
                $info2 = $meta2->getRelationInfo($target);
                $id1 = $meta1->getSingleIdentifierProperty();

                if (!$id1) {
                    throw new InvalidQueryException("Failed to complete join condition from entity with " . ($meta1->getIdentifierProperties() ? 'complex' : 'no') . " identifier");
                }

                $node->condition = new Node\BinaryExpression(
                    new Node\Identifier($alias . '.' . $info2['fk']),
                    '=',
                    new Node\Identifier($relation['from'] . '.' . $id1)
                );
            } else {
                throw new InvalidQueryException("Failed to complete join condition, no relation info available");
            }
        }
    }

    public function leave(Node\Node $node, Context $context) : void {

    }

}
