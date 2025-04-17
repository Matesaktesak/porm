<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Visitor;

use PORM\Exceptions\InvalidQueryException;
use PORM\Metadata\Provider;
use PORM\SQL\AST\Context;
use PORM\SQL\AST\IEnterVisitor;
use PORM\SQL\AST\Node;


class IdentifierResolverVisitor implements IEnterVisitor {

    private Provider $metadataProvider;


    public function __construct(Provider $metadataProvider) {
        $this->metadataProvider = $metadataProvider;
    }


    public function getNodeTypes() : array {
        return [
            Node\Identifier::class,
        ];
    }

    /**
     * @throws InvalidQueryException
     */
    public function enter(Node\Node $node, Context $context) : void {
        /** @var Node\Identifier $node */

        if ($context->getPropertyName() === 'alias' || $context->getParent(Node\TableReference::class)) {
            return;
        }

        if (str_contains($node->value, '.')) {
            @list($alias, $property, $sub) = explode('.', $node->value);
        } else {
            $property = $node->value;
            $alias = $sub = null;
        }

        if ($property === '*') {
            return;
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
                $entity = $query->hasMappedEntity($alias) ? $query->getMappedEntity($alias) : null;
                $meta = $entity ? $this->metadataProvider->get($entity) : null;

                if (isset($fields[$property])) {
                    if (!$sub) {
                        $info = $fields[$property];
                    } else {
                        throw new InvalidQueryException("Property '{$alias}.{$property}' has no subfields");
                    }
                } else if (!$meta || !$meta->hasRelation($property)) {
                    throw new InvalidQueryException("Unknown field: '{$node->value}'");
                }
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
        }

        if (isset($info)) {
            if (!$node->hasTypeInfo()) {
                $node->setTypeInfo($info['type'], $info['nullable']);
            }

            if (isset($info['field'])) {
                $node->value = ($alias ? $alias . '.' : '') . $info['field'];
            }
        }

        if (isset($entity)) {
            $node->setMappingInfo($entity, $property);
        }
    }

}
