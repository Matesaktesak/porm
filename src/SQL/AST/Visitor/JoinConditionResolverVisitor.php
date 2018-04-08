<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Visitor;

use PORM\Metadata\Provider;
use PORM\Exceptions\InvalidQueryException;
use PORM\SQL\AST\Context;
use PORM\SQL\AST\IEnterVisitor;
use PORM\SQL\AST\Node;


class JoinConditionResolverVisitor implements IEnterVisitor {

    private $metadataProvider;


    public function __construct(Provider $provider) {
        $this->metadataProvider = $provider;
    }


    public function getNodeTypes() : array {
        return [
            Node\JoinExpression::class,
        ];
    }

    public function enter(Node\Node $node, Context $context) : void {
        /** @var Node\JoinExpression $node */
        $query = $context->getClosestQueryNode();

        if (!$node->condition && $node->isRelation()) {
            $relation = $node->getRelationInfo();
            $meta1 = $this->metadataProvider->get($query->getMappedEntity($relation['from']));
            $info1 = $meta1->getRelationInfo($relation['property']);
            $meta2 = $this->metadataProvider->get($info1['target']);
            $alias = $node->alias ? $node->alias->value : $meta2->getEntityClass();

            if (isset($info1['fk'])) {
                $id2 = $meta2->getSingleIdentifierProperty();

                $node->condition = new Node\BinaryExpression(
                    new Node\Identifier($alias . '.' . $id2),
                    '=',
                    new Node\Identifier($relation['from'] . '.' . $info1['fk'])
                );
            } else if ($meta2->hasRelationTarget($meta1->getEntityClass(), $relation['property'])) {
                $target = $meta2->getRelationTarget($meta1->getEntityClass(), $relation['property']);
                $info2 = $meta2->getRelationInfo($target);
                $id1 = $meta1->getSingleIdentifierProperty();

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

}
