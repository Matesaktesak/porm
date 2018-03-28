<?php

declare(strict_types=1);

namespace PORM\SQL\AST;

use PORM\Metadata\Entity;
use PORM\Metadata\Registry;
use PORM\SQL\InvalidQueryException;


class EntityResolverVisitor implements IVisitor {

    private $metadataRegistry;


    public function __construct(Registry $registry) {
        $this->metadataRegistry = $registry;
    }


    public function getNodeTypes() : array {
        return [
            Node\TableExpression::class,
            Node\JoinExpression::class,
            Node\TableReference::class,
        ];
    }


    public function init() : void {

    }

    public function enter(Node\Node $node, Context $context) : void {
        if ($context->getNodeType() === Node\TableReference::class) {
            if (empty($node->attributes['visited'])) {
                /** @var Node\TableReference $node */
                $this->visit($context->getClosestQueryNode(), $node, $context->getParent(Node\TableExpression::class, Node\JoinExpression::class));
            }
        } else {
            /** @var Node\TableExpression|Node\JoinExpression $node */
            if ($node->table instanceof Node\TableReference) {
                $this->visit($context->getClosestQueryNode(), $node->table, $node);
            }
        }
    }

    public function leave(Node\Node $node, Context $context) : void {

    }


    private function visit(Node\Query $query, Node\TableReference $node, ?Node\TableExpression $expr = null) : void {
        if (strpos($node->name->value, '.')) {
            [$alias, $relation] = explode('.', $node->name->value, 2);

            if ($query->hasMappedEntity($alias)) {
                $meta = $this->metadataRegistry->get($query->getMappedEntity($alias));

                if ($meta->hasRelation($relation)) {
                    $info = $meta->getRelationInfo($relation);
                    $target = $this->metadataRegistry->get($info['target']);

                    if ($expr) {
                        $expr->setRelationInfo($alias, $relation);
                    }
                } else {
                    throw new InvalidQueryException("Unknown relation '{$node->name->value}'");
                }
            } else {
                throw new InvalidQueryException("Unknown alias '$alias'");
            }
        } else {
            $target = $this->metadataRegistry->get($node->name->value);
        }

        $query->mapResource($this->extractFieldInfo($target), $expr->alias->value ?? null, $target->getEntityClass());
        $node->name->value = $target->getTableName();
        $node->attributes['visited'] = true;
    }


    private function extractFieldInfo(Entity $entity) : array {
        $fields = [];

        foreach ($entity->getProperties() as $prop) {
            $info = $entity->getPropertyInfo($prop);

            $fields[$prop] = [
                'field' => $info['column'],
                'type' => $info['type'],
                'nullable' => $info['nullable'],
            ];
        }

        return $fields;
    }

}
