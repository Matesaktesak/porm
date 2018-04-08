<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Visitor;

use PORM\Exceptions\InvalidQueryException;
use PORM\SQL\AST\Context;
use PORM\SQL\AST\IEnterVisitor;
use PORM\SQL\AST\Node;


class ParameterResolverVisitor implements IEnterVisitor {

    private $parameterContainerPredicate;



    public function __construct() {
        $this->parameterContainerPredicate = function (Node\Node $node) : bool {
            return isset($node->attributes['parameters']);
        };
    }


    public function getNodeTypes() : array {
        return [
            Node\ParameterReference::class,
            Node\NamedParameterReference::class,
        ];
    }

    public function enter(Node\Node $node, Context $context) : void {
        if ($context->getNodeType() === Node\NamedParameterReference::class) { /** @var Node\NamedParameterReference $node */
            $context->replaceWith(Node\ParameterReference::replacing($node));
            return;
        }

        /** @var Node\ParameterReference $node */

        /** @var Node\Query $query */
        $query = $context->getClosestQueryNode();

        if ($node->hasInfo()) {
            $info = $node->getInfo();
        } else {
            $parent = $context->getParent(Node\ArithmeticExpression::class, Node\BinaryExpression::class, Node\LogicExpression::class);

            if ($parent) {
                /** @var Node\ArithmeticExpression|Node\BinaryExpression|Node\LogicExpression $parent */
                $target = $node === $parent->right ? $parent->left : $parent->right;

                if ($target instanceof Node\Identifier && $target->hasTypeInfo()) {
                    $info = $target->getTypeInfo();
                }
            }
        }

        if (!$node->hasValue() && ($container = $context->getClosestNodeMatching($this->parameterContainerPredicate))) {
            $params = $container->attributes['parameters'];

            if (isset($node->attributes['replaces'])) {
                $key = $node->attributes['replaces'];
            } else if (isset($container->attributes['parameter_ids'])) {
                $key = ++$container->attributes['parameter_ids'];
            } else {
                $key = $container->attributes['parameter_ids'] = 0;
            }

            if (isset($params[$key]) || key_exists($key, $params)) {
                $node->setValue($params[$key]);
            } else if (!isset($node->attributes['replaces'])) {
                throw new InvalidQueryException("Positional parameters set in an Expression instance must be provided with the same Expression instance");
            }
        }

        if ($node->hasValue()) {
            $id = $query->registerFixedParameter($node->getValue(), $info['type'] ?? null, $info['nullable'] ?? null);
        } else {
            $id = $query->registerRequiredParameter($node->attributes['replaces'] ?? null, $info['type'] ?? null, $info['nullable'] ?? null);
        }

        $node->setId($id);
    }

}
