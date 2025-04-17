<?php

declare(strict_types=1);

namespace PORM\SQL\AST;


class Context {

    public array $data = [];

    private Walker $walker;

    public function __construct(Walker $walker) {
        $this->walker = $walker;
    }

    public function getNodeType(): ?string {
        return $this->walker->getNodeType();
    }

    public function getPropertyName(): ?string {
        return $this->walker->getPropertyName();
    }

    public function replaceWith(Node\Node $node): void {
        $this->walker->replaceWith($node);
    }

    public function skipChildren(): void {
        $this->walker->skipChildren();
    }

    public function getDepth(): int {
        return $this->walker->getDepth();
    }

    public function getPreviousSibling(): ?Node\Node {
        return $this->walker->getPreviousSibling();
    }

    public function getNextSibling(): ?Node\Node {
        return $this->walker->getNextSibling();
    }

    public function getParent(string ...$types): ?Node\Node {
        return $this->walker->getParentNode(... $types);
    }

    public function getParentType(string ...$types): ?string {
        return $this->walker->getParentType(... $types);
    }

    /**
     * @return Node\Node[]
     */
    public function getParents(string ...$types): array {
        return $this->walker->getParentNodes(... $types);
    }

    /**
     * @return Node\Query[]
     */
    public function getParentQueries(): array {
        return $this->walker->getParentNodes(Node\SelectQuery::class, Node\InsertQuery::class, Node\UpdateQuery::class, Node\DeleteQuery::class);
    }

    public function getClosestNode(string ...$types): ?Node\Node {
        return $this->walker->getClosestNode(... $types);
    }

    public function getClosestType(string ...$types): ?string {
        return $this->walker->getClosestType(... $types);
    }

    public function getClosestNodeMatching(callable $predicate): ?Node\Node {
        return $this->walker->getClosestNodeMatching($predicate);
    }

    public function getClosestQueryNode(): ?Node\Query {
        /** @var Node\Query|null $node */
        $node = $this->walker->getClosestNode(Node\SelectQuery::class, Node\InsertQuery::class, Node\UpdateQuery::class, Node\DeleteQuery::class);
        return $node;
    }

    public function getRootNode(): ?Node\Node {
        return $this->walker->getRootNode();
    }

    public function getRootType(): ?string {
        return $this->walker->getRootType();
    }


}
