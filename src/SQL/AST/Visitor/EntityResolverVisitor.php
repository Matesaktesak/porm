<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Visitor;

use PORM\Metadata\Entity;
use PORM\Metadata\Provider;
use PORM\Exceptions\InvalidQueryException;
use PORM\SQL\AST\Context;
use PORM\SQL\AST\IEnterVisitor;
use PORM\SQL\AST\Node;


class EntityResolverVisitor implements IEnterVisitor {

    private $metadataProvider;


    public function __construct(Provider $provider) {
        $this->metadataProvider = $provider;
    }


    public function getNodeTypes() : array {
        return [
            Node\SelectQuery::class,
            Node\InsertQuery::class,
            Node\UpdateQuery::class,
            Node\DeleteQuery::class,
        ];
    }

    public function enter(Node\Node $node, Context $context) : void {
        switch ($context->getNodeType()) {
            case Node\InsertQuery::class: /** @var Node\InsertQuery $node */
                $this->visit($node, $node->into);
                return; // visit insert query directly and return


            case Node\SelectQuery::class: /** @var Node\SelectQuery $node */
                $expressions = $node->from;
                break;

            case Node\UpdateQuery::class: /** @var Node\UpdateQuery $node */
                $expressions = [$node->table];
                break;

            case Node\DeleteQuery::class: /** @var Node\DeleteQuery $node */
                $expressions = [$node->from];
                break;

            default:
                return;
        }

        foreach ($expressions as $expr) {
            if ($expr->table instanceof Node\TableReference) {
                $this->visit($node, $expr->table, $expr);
            }
        }
    }


    private function visit(Node\Query $query, Node\TableReference $node, ?Node\TableExpression $expr = null) : void {
        if (strpos($node->name->value, '.')) {
            [$alias, $relation] = explode('.', $node->name->value, 2);

            if ($query->hasMappedEntity($alias)) {
                $meta = $this->metadataProvider->get($query->getMappedEntity($alias));

                if ($meta->hasRelation($relation)) {
                    $info = $meta->getRelationInfo($relation);
                    $target = $this->metadataProvider->get($info['target']);

                    if ($expr) {
                        $expr->setRelationInfo($alias, $relation);
                    }
                } else {
                    throw new InvalidQueryException("Unknown relation '{$node->name->value}'");
                }
            } else {
                throw new InvalidQueryException("Unknown alias '$alias'");
            }
        } else if ($this->metadataProvider->has($node->name->value)) {
            $target = $this->metadataProvider->get($node->name->value);
        } else {
            return;
        }

        $query->mapResource(
            $target->getPropertiesInfo(),
            $expr->alias->value ?? null,
            $target->getEntityClass(),
            $expr instanceof Node\JoinExpression && $expr->type === Node\JoinExpression::JOIN_LEFT
        );

        $node->name->value = $target->getTableName();
    }

}
