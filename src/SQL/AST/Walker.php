<?php

declare(strict_types=1);

namespace PORM\SQL\AST;


class Walker {

    /** @var IVisitor[][] */
    private $visitors = [];

    /** @var \SplObjectStorage|Context[] */
    private $contexts;

    /** @var int */
    private $depth = -1;

    /** @var bool */
    private $skip = false;

    /** @var Node\Node|null */
    private $replacement = null;

    /** @var string[] */
    private $properties = [];

    /** @var array<Node\Node|string> */
    private $stack = [];




    public function addVisitor(IVisitor $visitor) : void {
        if (!isset($this->contexts)) {
            $this->contexts = new \SplObjectStorage();
        }

        $this->contexts[$visitor] = new Context($this);

        foreach ($visitor->getNodeTypes() as $type) {
            $this->visitors[$type][] = $visitor;
        }
    }


    public function walk(Node\Node $root) : void {
        if (!empty($this->visitors)) {
            $this->init($this->contexts);
            $this->visit($root, $this->visitors, $this->contexts);
        }
    }

    public function apply(Node\Node $root, IVisitor ... $visitors) : void {
        $contexts = new \SplObjectStorage();
        $map = [];

        foreach ($visitors as $visitor) {
            $contexts[$visitor] = new Context($this);

            foreach ($visitor->getNodeTypes() as $type) {
                $map[$type][] = $visitor;
            }
        }

        $this->init($contexts);
        $this->visit($root, $map, $contexts);
    }


    private function init(\SplObjectStorage $contexts) : void {
        foreach ($contexts as $visitor) {
            $visitor->init();
        }
    }


    private function visit(Node\Node $node, array $visitors, \SplObjectStorage $contexts) : ?Node\Node {
        $class = get_class($node);
        $nodeVisitors = $visitors[$class] ?? [];
        $this->stack[] = [$class, $node];
        $this->depth++;
        $this->skip = false;
        $this->replacement = null;

        foreach ($nodeVisitors as $visitor) {
            $visitor->enter($node, $contexts[$visitor]);

            if ($this->replacement) {
                array_pop($this->stack);
                $this->depth--;
                return $this->replacement;
            }
        }

        if (!$this->skip) {
            foreach ($node->getTraversableProperties() as $property => $array) {
                array_unshift($this->properties, $property);

                if ($array) {
                    foreach ($node->$property as & $value) {
                        if ($value instanceof Node\Node) {
                            while ($replacement = $this->visit($value, $visitors, $contexts)) {
                                $value = $replacement;
                            }
                        }
                    }
                } else if ($node->$property instanceof Node\Node) {
                    while ($replacement = $this->visit($node->$property, $visitors, $contexts)) {
                        $node->$property = $replacement;
                    }
                }

                array_shift($this->properties);
            }

            foreach ($nodeVisitors as $visitor) {
                $visitor->leave($node, $contexts[$visitor]);

                if ($this->replacement) {
                    array_pop($this->stack);
                    $this->depth--;
                    return $this->replacement;
                }
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
