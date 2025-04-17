<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Visitor;

use PORM\Metadata\Provider;
use PORM\SQL\AST\Context;
use PORM\SQL\AST\IEnterVisitor;
use PORM\SQL\AST\Node;


class AssignmentJoiningVisitor implements IEnterVisitor {

    private Provider $metadataProvider;


    public function __construct(Provider $metadataProvider) {
        $this->metadataProvider = $metadataProvider;
    }


    public function getNodeTypes() : array {
        return [
            Node\SelectQuery::class,
        ];
    }


    public function enter(Node\Node $node, Context $context) : void {
        /** @var Node\SelectQuery $node */

        $knownTables = [];

        for ($i = 0, $n = count($node->from); $i < $n; $i++) {
            $expr = $node->from[$i];

            if ($expr->isRelation()) {
                $rel = $expr->getRelationInfo();
                $entity = $node->getMappedEntity($rel['from']);
                $meta = $this->metadataProvider->get($entity);
                $info = $meta->getRelationInfo($rel['property']);
                $target = $this->metadataProvider->get($info['target']);

                if (!empty($info['via']) && !isset($knownTables[$info['via']['table']])) {
                    $alias = '_x' . $i;

                    $via = new Node\JoinExpression(new Node\TableReference($info['via']['table']), $expr->type ?? Node\JoinExpression::JOIN_LEFT, $alias);
                    $via->condition = new Node\BinaryExpression(
                        new Node\Identifier($alias . '.' . $info['via']['localColumn']),
                        '=',
                        new Node\Identifier($rel['from'] . '.' . $meta->getSingleIdentifierProperty())
                    );

                    array_splice($node->from, $i, 0, [$via]);
                    $i++;
                    $n++;

                    if (!($expr instanceof Node\JoinExpression)) {
                        $expr = new Node\JoinExpression($expr->table, Node\JoinExpression::JOIN_INNER, $expr->alias);
                        $node->from[$i] = $expr;
                    } else {
                        $expr->type = Node\JoinExpression::JOIN_INNER;
                    }

                    if (!$expr->condition) {
                        $expr->condition = new Node\BinaryExpression(
                            new Node\Identifier(($expr->alias ? $expr->alias->value . '.' : '') . $target->getSingleIdentifierProperty()),
                            '=',
                            new Node\Identifier($alias . '.' . $info['via']['remoteColumn'])
                        );
                    }
                }
            } else if ($expr->table instanceof Node\TableReference) {
                $knownTables[$expr->table->name->value] = 1;
            }
        }
    }

}
