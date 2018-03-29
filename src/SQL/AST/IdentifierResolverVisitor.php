<?php

declare(strict_types=1);

namespace PORM\SQL\AST;

use PORM\Exceptions\InvalidQueryException;


class IdentifierResolverVisitor implements IVisitor {


    public function getNodeTypes() : array {
        return [
            Node\Identifier::class,
        ];
    }

    public function init() : void {

    }

    public function enter(Node\Node $node, Context $context) : void {
        /** @var Node\Identifier $node */

        if ($context->getPropertyName() === 'alias' || $context->getParent(Node\TableReference::class)) {
            return;
        }

        if (strpos($node->value, '.') !== false) {
            /** @var string $alias */
            [$alias, $property] = explode('.', $node->value);
        } else {
            $property = $node->value;
            $alias = null;
        }

        if ($alias) {
            /** @var Node\Query $query */
            $query = $context->getClosestNodeMatching(function(Node\Node $node, string $type) use ($alias) : bool {
                /** @var Node\Query $node */
                return in_array($type, [Node\SelectQuery::class, Node\InsertQuery::class, Node\UpdateQuery::class, Node\DeleteQuery::class], true)
                    && $node->hasMappedResource($alias);
            });

            if ($query) {
                $fields = $query->getMappedResourceFields($alias);

                if (isset($fields[$property])) {
                    $info = $fields[$property];
                    $entity = $query->hasMappedEntity($alias) ? $query->getMappedEntity($alias) : null;
                } else {
                    throw new InvalidQueryException("Unknown field: '{$node->value}'");
                }
            } else {
                throw new InvalidQueryException("Unknown alias: '$alias'");
            }
        } else {
            foreach ($context->getParentQueries() as $query) {
                foreach ($query->getMappedResources() as $resource) {
                    if (isset($resource['fields'][$property])) {
                        if (!isset($info)) {
                            $info = $resource['fields'][$property];
                            $entity = $resource['entity'];
                        } else {
                            throw new InvalidQueryException("Ambiguous identifier '{$node->value}'");
                        }
                    }
                }
            }

            if (!isset($info)) {
                throw new InvalidQueryException("Unknown field: '{$node->value}'");
            }
        }

        if (!$node->hasTypeInfo()) {
            $node->setTypeInfo($info['type'], $info['nullable']);
        }

        if (isset($info['field'])) {
            $node->value = ($alias ? $alias . '.' : '') . $info['field'];
        }

        if (isset($entity)) {
            $node->setMappingInfo($entity, $property);
        }
    }

    public function leave(Node\Node $node, Context $context) : void {

    }

}
