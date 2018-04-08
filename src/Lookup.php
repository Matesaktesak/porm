<?php

declare(strict_types=1);

namespace PORM;

use PORM\Exceptions\NoResultException;


class Lookup implements \IteratorAggregate, \ArrayAccess, \Countable {

    private $manager;

    private $queryBuilder;

    private $metadata;

    private $alias;

    private $associateBy = null;

    private $relations = [];

    private $aggregations = [];

    /** @var \Generator|array */
    private $result = null;

    /** @var int */
    private $count = null;



    public function __construct(EntityManager $manager, Metadata\Entity $metadata, ?string $alias = null) {
        $this->manager = $manager;
        $this->queryBuilder = $manager->createQueryBuilder($metadata, $alias);
        $this->metadata = $metadata;
        $this->alias = $alias;
    }


    public function where(array $where) : self {
        $this->queryBuilder->where($where);
        return $this;
    }

    public function andWhere(array $where) : self {
        $this->queryBuilder->andWhere($where);
        return $this;
    }

    public function orWhere(array $where) : self {
        $this->queryBuilder->orWhere($where);
        return $this;
    }

    public function orderBy($orderBy) : self {
        $this->queryBuilder->orderBy(is_array($orderBy) ? $orderBy : [$orderBy]);
        return $this;
    }

    public function limit(int $limit) : self {
        $this->queryBuilder->limit($limit);
        return $this;
    }

    public function offset(int $offset) : self {
        $this->queryBuilder->offset($offset);
        return $this;
    }

    public function associateBy(?string $associateBy) : self {
        $this->associateBy = $associateBy;
        return $this;
    }

    public function join($relation, ?string $alias = null, string $type = 'LEFT') : self {
        $this->queryBuilder->join($relation instanceof Lookup ? $relation->getQueryBuilder() : $relation, $alias, null, $type);
        return $this;
    }

    public function leftJoin($relation, ?string $alias = null) : self {
        $this->queryBuilder->leftJoin($relation instanceof Lookup ? $relation->getQueryBuilder() : $relation, $alias);
        return $this;
    }

    public function innerJoin($relation, ?string $alias = null) : self {
        $this->queryBuilder->innerJoin($relation instanceof Lookup ? $relation->getQueryBuilder() : $relation, $alias);
        return $this;
    }

    public function with(string ... $relations) : self {
        array_push($this->relations, ... array_diff($relations, $this->relations));
        return $this;
    }

    public function withAggregate(string ... $properties) : self {
        array_push($this->aggregations, ... array_diff($properties, $this->aggregations));
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




    public function getQueryBuilder() : QueryBuilder {
        return $this->queryBuilder;
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
            $this->queryBuilder->limit(1);
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
            $this->result = $this->manager->execute($this->queryBuilder->getQuery(), $this->metadata);

            if ($this->relations) {
                $this->ensureArrayResult();
                $this->manager->loadRelations($this->metadata, $this->result, ... $this->relations);
                $eager = false;
            }

            if ($this->aggregations) {
                $this->ensureArrayResult();
                $this->manager->loadAggregate($this->metadata, $this->result, ... $this->aggregations);
                $eager = false;
            }

            if ($this->associateBy) {
                $associateBy = preg_split('/\||(\[])/', $this->associateBy, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
                $result = $this->result;
                $this->result = [];
                $this->count = 0;
                $eager = false;

                foreach ($result as $entity) {
                    ++$this->count;
                    $cursor = &$this->result;

                    foreach ($associateBy as $prop) {
                        if ($prop === '[]') {
                            $cursor = &$cursor[];
                        } else {
                            $cursor = &$cursor[$entity->$prop];
                        }
                    }

                    $cursor = $entity;
                    unset($cursor);
                }
            }
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
                $field = ($this->alias ? $this->alias . '.' : '') . ($this->metadata->getSingleIdentifierProperty(false) ?? '*');

                $qb = clone $this->queryBuilder;
                $qb->select(['_count' => new SQL\Expression('COUNT(' . $field . ')')])
                    ->orderBy(null)
                    ->limit(null)
                    ->offset(null);

                $result = $this->manager->execute($qb->getQuery());
                $this->count = (int) $result->fetchSingle();
            }
        }
    }
}
