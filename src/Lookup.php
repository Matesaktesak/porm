<?php

declare(strict_types=1);

namespace PORM;


use PORM\SQL\NoResultException;

class Lookup implements \IteratorAggregate, \ArrayAccess, \Countable {

    private $where = null;

    private $orderBy = null;

    private $limit = null;

    private $offset = null;

    private $associateBy = null;

    private $relations = [];

    private $aggregations = [];


    private $manager;

    private $metadata;

    /** @var \Generator|array */
    private $result = null;

    /** @var int */
    private $count = null;



    public static function empty(EntityManager $manager, Metadata\Entity $metadata) : self {
        $lookup = new static($manager, $metadata);
        $lookup->result = [];
        $lookup->count = 0;
        return $lookup;
    }

    public function __construct(EntityManager $manager, Metadata\Entity $metadata, ?array $where = null, $orderBy = null, ?int $limit = null, ?int $offset = null, ?string $associateBy = null) {
        $this->manager = $manager;
        $this->metadata = $metadata;
        $this->where = $where;
        $this->orderBy = $orderBy;
        $this->limit = $limit;
        $this->offset = $offset;
        $this->associateBy = $associateBy;
    }


    public function where(?array $where) : self {
        $this->where = $where;
        return $this;
    }

    public function andWhere(array $where) : self {
        $this->where[] = $where;
        return $this;
    }

    public function orWhere(array $where) : self {
        $this->where[] = 'or';
        $this->where[] = $where;
        return $this;
    }

    public function orderBy($orderBy) : self {
        $this->orderBy = is_array($orderBy) ? $orderBy : [$orderBy];
        return $this;
    }

    public function limit(?int $limit) : self {
        $this->limit = $limit;
        return $this;
    }

    public function offset(?int $offset) : self {
        $this->offset = $offset;
        return $this;
    }

    public function associateBy(?string $associateBy) : self {
        $this->associateBy = $associateBy;
        return $this;
    }

    public function with(string ... $relations) : self {
        $this->relations = array_fill_keys($relations, false) + $this->relations;
        return $this;
    }

    public function onlyWith(string ... $relations) : self {
        $this->relations = array_fill_keys($relations, true) + $this->relations;
        return $this;
    }

    public function withAggregate(string ... $properties) : self {
        array_push($this->aggregations, ... $properties);
        return $this;
    }




    public function isLoaded() : bool {
        return $this->result !== null;
    }

    public function load() : self {
        $this->loadResult();
        return $this;
    }

    public function extract(string $property, ?string $key = null) : array {
        $this->loadResult(true);
        return array_column($this->result, $property, $key);
    }

    public function update(array $data) : int {
        return $this->manager->updateLookup($this, $data);
    }

    public function delete() : int {
        return $this->manager->deleteLookup($this);
    }




    public function getEntityMetadata() : Metadata\Entity {
        return $this->metadata;
    }

    public function getWhere() : ?array {
        return $this->where;
    }

    public function getOrderBy() : ?array {
        return $this->orderBy;
    }

    public function getLimit() : ?int {
        return $this->limit;
    }

    public function getOffset() : ?int {
        return $this->offset;
    }

    public function getAssociateBy() : ?string {
        return $this->associateBy;
    }

    public function getRelations() : array {
        return $this->relations;
    }

    public function getAggregations() : array {
        return array_unique($this->aggregations);
    }




    public function getIterator() : \Generator {
        $this->loadResult();
        yield from $this->result;
    }

    public function count(bool $loadResult = false) : int {
        if ($loadResult) {
            $this->loadResult();
        }

        $this->loadCount();
        return $this->count;
    }

    public function offsetExists($offset) {
        $this->loadResult(true);
        return isset($this->result[$offset]);
    }

    public function offsetGet($offset) {
        $this->loadResult(true);
        return $this->result[$offset];
    }

    public function offsetSet($offset, $value) {
        throw new \RuntimeException('Lookup result is read-only');
    }

    public function offsetUnset($offset) {
        throw new \RuntimeException('Lookup result is read-only');
    }


    public function toArray() : array {
        $this->loadResult(true);
        return $this->result;
    }

    public function keys() : array {
        $this->loadResult(true);
        return array_keys($this->result);
    }

    public function first(bool $need = false) {
        if ($this->result === null) {
            $this->limit = 1;
            $this->loadResult();
        }

        $this->ensureArrayResult();

        if ($need && empty($this->result)) {
            throw new NoResultException();
        }

        return reset($this->result);
    }


    private function loadResult(bool $eager = false) : void {
        if ($this->result === null) {
            $this->result = $this->manager->loadLookup($this);
        }

        if ($eager) {
            $this->ensureArrayResult();
        }
    }

    private function ensureArrayResult() : void {
        if (!is_array($this->result)) {
            $this->result = iterator_to_array($this->result);
        }
    }

    private function loadCount() : void {
        if ($this->count === null) {
            if ($this->result !== null) {
                $this->ensureArrayResult();
                $this->count = count($this->result);
            } else {
                $this->count = $this->manager->countLookup($this);
            }
        }
    }
}
