<?php

declare(strict_types=1);

namespace PORM\SQL\AST;


class Walker {

    /** @var IVisitor[][] */
    private array $visitors = [];

    /** @var \SplObjectStorage|Context[] */
    private \SplObjectStorage|array $contexts;

    private int $depth = -1;

    private Node\Node|null $replacement = null;

    /** @var string[] */
    private array $properties = [];

    /** @var array<Node\Node|string> */
    private array $stack = [];
    private bool $skip;


    public function addVisitor(IVisitor $visitor) : void {
        if (!isset($this->contexts)) {
            $this->contexts = new \SplObjectStorage();
        }

        $this->insertVisitor($this->contexts, $this->visitors, $visitor);
    }


    public function walk(Node\Node $root) : void {
        if (!empty($this->visitors)) {
            $this->visit($root, $this->visitors, $this->contexts);
        }
    }

    public function apply(Node\Node $root, IVisitor ...$visitors) : void {
        $contexts = new \SplObjectStorage();
        $map = [];

        foreach ($visitors as $visitor) {
            $this->insertVisitor($contexts, $map, $visitor);
        }

        $this->visit($root, $map, $contexts);
    }


    private function insertVisitor(\SplObjectStorage $contexts, array & $visitors, IVisitor $visitor) : void {
        $contexts[$visitor] = new Context($this);

        if ($visitor instanceof IEnterVisitor) {
            foreach ($visitor->getNodeTypes() as $type) {
                $visitors[IVisitor::ENTER][$type][] = $visitor;
            }
        }

        if ($visitor instanceof ILeaveVisitor) {
            foreach ($visitor->getNodeTypes() as $type) {
                $visitors[IVisitor::LEAVE][$type][] = $visitor;
            }
        }
    }


    private function visit(Node\Node $node, array $visitors, \SplObjectStorage $contexts) : ?Node\Node {
        $class = get_class($node);
        $enter = $visitors[IVisitor::ENTER][$class] ?? [];
        $leave = $visitors[IVisitor::LEAVE][$class] ?? [];
        $this->stack[] = [$class, $node];
        $this->depth++;
        $this->skip = false;
        $this->replacement = null;

        foreach ($enter as $visitor) {
            $visitor->enter($node, $contexts[$visitor]);

            if ($this->replacement) {
                array_pop($this->stack);
                $this->depth--;
                return $this->replacement;
            }
        }

        foreach ($node->getTraversableProperties() as $property => $array) {
            array_unshift($this->properties, $property);

            if ($array) {
                foreach ($node->$property as & $value) {
                    while ($replacement = $this->visit($value, $visitors, $contexts)) {
                        $value = $replacement;
                    }
                }
            } else if ($node->$property) {
                while ($replacement = $this->visit($node->$property, $visitors, $contexts)) {
                    $node->$property = $replacement;
                }
            }

            array_shift($this->properties);
        }

        foreach ($leave as $visitor) {
            $visitor->leave($node, $contexts[$visitor]);

            if ($this->replacement) {
                array_pop($this->stack);
                $this->depth--;
                return $this->replacement;
            }
        }

        array_pop($this->stack);
        $this->depth--;
        return null;
    }



    public function getNodeType() : ?string {
        return $this->stack[$this->depth][0] ?? null;
    }

    public function getPropertyName() : ?string {
        return $this->properties[0] ?? null;
    }

    public function replaceWith(Node\Node $node) : void {
        $this->replacement = $node;
    }

    public function skipChildren() : void {
        $this->skip = true;
    }

    public function getDepth() : int {
        return $this->depth;
    }

    public function getParentNode(string ... $types) : ?Node\Node {
        if ($this->depth > 0 && (!$types || in_array($this->stack[$this->depth - 1][0], $types, true))) {
            return $this->stack[$this->depth - 1][1];
        } else {
            return null;
        }
    }

    public function getParentType(string ... $types) : ?string {
        if ($this->depth > 0 && (!$types || in_array($this->stack[$this->depth - 1][0], $types, true))) {
            return $this->stack[$this->depth - 1][0];
        } else {
            return null;
        }
    }

    public function getParentNodes(string ... $types) : array {
        $nodes = [];

        for ($i = $this->depth - 1; $i >= 0; $i--) {
            if (in_array($this->stack[$i][0], $types, true)) {
                $nodes[] = $this->stack[$i][1];
            }
        }

        return $nodes;
    }

    public function getClosestNode(string ... $types) : ?Node\Node {
        for ($i = $this->depth - 1; $i >= 0; $i--) {
            if (in_array($this->stack[$i][0], $types, true)) {
                return $this->stack[$i][1];
            }
        }

        return null;
    }

    public function getClosestType(string ... $types) : ?string {
        for ($i = $this->depth - 1; $i >= 0; $i--) {
            if (in_array($this->stack[$i][0], $types, true)) {
                return $this->stack[$i][0];
            }
        }

        return null;
    }

    public function getClosestNodeMatching(callable $predicate) : ?Node\Node {
        for ($i = $this->depth - 1; $i >= 0; $i--) {
            if (call_user_func($predicate, $this->stack[$i][1], $this->stack[$i][0])) {
                return $this->stack[$i][1];
            }
        }

        return null;
    }

    public function getRootNode() : ?Node\Node {
        return $this->stack[0][1] ?? null;
    }

    public function getRootType() : ?string {
        return $this->stack[0][0] ?? null;
    }
}
