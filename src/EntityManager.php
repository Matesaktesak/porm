<?php

declare(strict_types=1);

namespace PORM;


use PORM\SQL\Expression;

class EntityManager {

    private $connection;

    private $mapper;

    private $metadataRegistry;

    private $translator;

    private $eventDispatcher;

    private $queryBuilder;

    private $identityMap = [];



    public function __construct(
        Connection $connection,
        Mapper $mapper,
        Metadata\Registry $metadataRegistry,
        SQL\Translator $translator,
        SQL\AST\Builder $queryBuilder,
        EventDispatcher $eventDispatcher
    ) {
        $this->connection = $connection;
        $this->mapper = $mapper;
        $this->metadataRegistry = $metadataRegistry;
        $this->translator = $translator;
        $this->queryBuilder = $queryBuilder;
        $this->eventDispatcher = $eventDispatcher;
    }


    public function getEntityMetadata(string $entityClass) : Metadata\Entity {
        return $this->metadataRegistry->get($entityClass);
    }


    public function get($entity, $id, bool $need = false) {
        $meta = $this->normalizeMeta($entity);
        $ast = $this->queryBuilder->buildSelectQuery($meta, null, null, $this->mapper->extractRawIdentifier($meta, $id));
        $query = $this->translator->compile($ast);
        $result = $this->execute($query);

        if ($result && ($row = $result->fetch())) {
            return $this->hydrateEntity($meta, $row);
        } else if ($need) {
            throw new SQL\NoResultException();
        } else {
            return null;
        }
    }



    public function find($entity, ?array $where = null, $orderBy = null, ?int $limit = null, ?int $offset = null, ?string $associateBy = null) : Lookup {
        return new Lookup($this, $this->normalizeMeta($entity), $where, $orderBy, $limit, $offset, $associateBy);
    }

    public function findFirst($entity, ?array $where = null, $orderBy = null) {
        return $this->find($entity, $where, $orderBy)->first();
    }



    public function persist($entity, $object) : self {
        $meta = $this->normalizeMeta($entity);

        if ($meta->isReadonly()) {
            throw new \InvalidArgumentException("Entity {$meta->getEntityClass()} is readonly");
        }

        $id = $this->mapper->extractIdentifier($meta, $object);
        $hash = implode('|', $id);
        $data = $this->mapper->extract($meta, $object, array_diff($meta->getProperties(), array_keys($id)));
        $orig = $this->identityMap[$meta->getEntityClass()][$hash]['data'] ?? [];
        $changeset = [];

        foreach ($data as $prop => $value) {
            if (!isset($orig[$prop]) && !key_exists($prop, $orig) || $orig[$prop] !== $data[$prop]) {
                $changeset[$prop] = [$orig[$prop] ?? null, $value];
            } else {
                unset($data[$prop]);
            }
        }

        $id = array_filter($id, function($v) { return $v !== null; });

        if (!empty($id) && !$meta->hasGeneratedProperty() && !isset($this->identityMap[$meta->getEntityClass()][$hash])) {
            $data = $id + $data;
            $id = null;
        }

        if (empty($id)) {
            $driver = $this->connection->getDriver();
            $platform = $this->connection->getPlatform();

            if ($meta->hasGeneratedProperty()) {
                $genProp = $meta->getGeneratedProperty();
                $genPropInfo = $meta->getPropertyInfo($genProp);

                if (!empty($genPropInfo['generator'])) {
                    $data[$genProp] = $platform->formatGenerator($genPropInfo['generator']);
                }
            } else {
                $genProp = $genPropInfo = null;
            }

            if ($started = !$this->connection->inTransaction()) {
                $this->connection->beginTransaction();
            }

            try {
                $this->eventDispatcher->dispatch($meta->getEntityClass() . '::prePersist', $object, $this);

                $ast = $this->queryBuilder->buildInsertQuery($meta, $data);
                $query = $this->translator->compile($ast);
                $result = $this->execute($query);

                if ($genProp) {
                    if ($platform->supportsReturningClause()) {
                        $id = (int) $result->fetchSingle();
                    } else {
                        $id = $driver->getLastGeneratedValue($genPropInfo['generator']);
                    }

                    $meta->getReflection($genProp)->setValue($object, $id);
                }

                $this->eventDispatcher->dispatch($meta->getEntityClass() . '::postPersist', $object, $this);

                if ($started) {
                    $this->connection->commit();
                }
            } catch (\Throwable $e) {
                if ($started) {
                    $this->connection->rollback();
                }

                throw $e;
            }
        } else if ($data) {
            if ($started = !$this->connection->inTransaction()) {
                $this->connection->beginTransaction();
            }

            try {
                $this->eventDispatcher->dispatch($meta->getEntityClass() . '::preUpdate', $object, $this, $changeset);

                $ast = $this->queryBuilder->buildUpdateQuery($meta, null, $data, $id);
                $query = $this->translator->compile($ast);
                $this->execute($query);

                $this->eventDispatcher->dispatch($meta->getEntityClass() . '::postUpdate', $object, $this, $changeset);

                if ($started) {
                    $this->connection->commit();
                }
            } catch (\Throwable $e) {
                if ($started) {
                    $this->rollback();
                }

                throw $e;
            }
        }

        return $this;
    }

    public function remove($entity, $object) : self {
        $meta = $this->normalizeMeta($entity);

        if ($meta->isReadonly()) {
            throw new \InvalidArgumentException("Entity {$meta->getEntityClass()} is readonly");
        }

        if ($started = !$this->connection->inTransaction()) {
            $this->connection->beginTransaction();
        }

        try {
            $this->eventDispatcher->dispatch($meta->getEntityClass() . '::preRemove', $object, $this);

            $id = $this->mapper->extractIdentifier($meta, $object);

            $ast = $this->queryBuilder->buildDeleteQuery($meta, $id);
            $query = $this->translator->compile($ast);
            $this->execute($query);

            $this->eventDispatcher->dispatch($meta->getEntityClass() . '::postRemove', $object, $this);

            if ($started) {
                $this->connection->commit();
            }
        } catch (\Throwable $e) {
            if ($started) {
                $this->connection->rollback();
            }

            throw $e;
        }

        return $this;
    }


    public function beginTransaction() : self {
        $this->connection->beginTransaction();
        return $this;
    }

    public function commit() : self {
        $this->connection->commit();
        return $this;
    }

    public function rollback() : self {
        $this->connection->rollback();
        return $this;
    }

    public function transactional(callable $callback, ... $args) {
        try {
            $this->beginTransaction();
            $result = call_user_func_array($callback, $args);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }


    public function loadLookup(Lookup $lookup) : \Generator {
        $ast = $this->queryBuilder->buildLookupSelectQuery($lookup);
        $query = $this->translator->compile($ast);

        $result = $this->execute($query);
        $meta = $lookup->getEntityMetadata();
        $entities = array_map(function(array $row) use ($meta) { return $this->hydrateEntity($meta, $row); }, iterator_to_array($result));

        if ($relations = $lookup->getRelations()) {
            $this->loadRelations($meta, $entities, ... array_keys($relations));
        }

        if ($aggregate = $lookup->getAggregations()) {
            $this->loadAggregation($meta, $entities, ... $aggregate);
        }

        if ($associateBy = $lookup->getAssociateBy()) {
            $associateBy = preg_split('/\||(\[])/', $associateBy, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            $root = [];

            foreach ($entities as $entity) {
                $cursor = &$root;

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

            yield from $root;
        } else {
            yield from $entities;
        }
    }


    public function countLookup(Lookup $lookup) : int {
        $ast = $this->queryBuilder->buildLookupSelectQuery($lookup, true);
        $query = $this->translator->compile($ast);
        $result = $this->execute($query);;
        $count = $result ? $result->fetchSingle() : null;
        return $count !== null ? (int) $count : 0;
    }


    public function updateLookup(Lookup $lookup, array $data) : int {
        $ast = $this->queryBuilder->buildLookupUpdateQuery($lookup, $data);
        $query = $this->translator->compile($ast);
        $this->execute($query);
        return $this->connection->getAffectedRows();
    }


    public function deleteLookup(Lookup $lookup) : int {
        $ast = $this->queryBuilder->buildLookupDeleteQuery($lookup);
        $query = $this->translator->compile($ast);
        $this->execute($query);
        return $this->connection->getAffectedRows();
    }


    public function createQueryBuilder($entity, ?string $alias = null) : QueryBuilder {
        return new QueryBuilder($this->translator, $this->queryBuilder, $this->normalizeMeta($entity), $alias);
    }


    public function expr(string $sql, ?array $parameters = null) : SQL\Expression {
        return new SQL\Expression($sql, $parameters);
    }


    public function query(string $query, ?array $parameters = null) : ?SQL\ResultSet {
        $query = $this->translator->translate($query);

        if ($parameters) {
            $query->setParameters($parameters);
        }

        return $this->execute($query);
    }

    public function execute(SQL\Query $query) : ?SQL\ResultSet {
        $result = $this->connection->query($query->getSql(), $this->mapper->convertToDb($query->getParameters(), $query->getParameterMap()));

        if ($result) {
            $map = $query->getResultMap();

            $result->setRowProcessor(function (array $row) use ($map) {
                return $this->mapper->convertFromDb($row, $map);
            });

            $result->setFieldMap(array_map(function (array $info) : string { return $info['alias']; }, $map));
        }

        return $result;
    }


    public function nativeQuery(string $query, ?array $parameters = null) : ?SQL\ResultSet {
        return $this->connection->query($query, $parameters ? $this->mapper->convertToDb($parameters) : null);
    }


    public function hydrateEntity(Metadata\Entity $meta, array $data) {
        $id = $this->mapper->extractRawIdentifier($meta, $data);
        $hash = implode('|', $id);
        $class = $meta->getEntityClass();

        if (isset($this->identityMap[$class][$hash])) {
            $entity = $this->identityMap[$class][$hash]['object'];
            $new = false;
        } else {
            $entity = $meta->getReflection()->newInstanceWithoutConstructor();
            $this->identityMap[$class][$hash]['object'] = $entity;
            $new = true;
        }

        $this->identityMap[$class][$hash]['data'] = $data;
        $this->mapper->hydrate($meta, $entity, $data);

        if ($new) {
            $this->eventDispatcher->dispatch($class . '::postLoad', $entity);
        }

        return $entity;
    }


    public function isAttached(Metadata\Entity $meta, $entity) : bool {
        $id = $this->mapper->extractIdentifier($meta, $entity);
        $hash = implode('|', $id);
        return isset($this->identityMap[$meta->getEntityClass()][$hash]);
    }

    public function attach(Metadata\Entity $meta, $entity) : void {
        $id = $this->mapper->extractIdentifier($meta, $entity);
        $hash = implode('|', $id);
        $class = $meta->getEntityClass();

        if (isset($this->identityMap[$class][$hash])) {
            if ($this->identityMap[$class][$hash]['object'] !== $entity) {
                throw new Exception("Identity map violation: another instance of entity {$class}#{$hash} is already attached");
            }
        } else {
            $this->identityMap[$class][$hash] = [
                'object' => $entity,
                'data' => [],
            ];
        }
    }

    public function detach(Metadata\Entity $meta, $entity) : void {
        $id = $this->mapper->extractIdentifier($meta, $entity);
        $hash = implode('|', $id);
        unset($this->identityMap[$meta->getEntityClass()][$hash]);
    }


    public function loadRelations(Metadata\Entity $meta, $entities, string ... $relations) : void {
        if (!($entities = $this->normalizeEntities($entities))) {
            return;
        }

        foreach ($relations as $prop) {
            @list ($prop, $rest) = explode('.', $prop, 2);

            if (!$meta->hasRelation($prop)) {
                throw new \InvalidArgumentException("Entity {$meta->getEntityClass()} has no relation '$prop'");
            } else {
                $related = $this->loadRelation($meta, $entities, $prop);

                if ($rest) {
                    $info = $meta->getRelationInfo($prop);
                    $this->loadRelations($this->getEntityMetadata($info['target']), $related, $rest);
                }
            }
        }
    }


    public function loadAggregate(Metadata\Entity $meta, $entities, string ... $properties) : void {
        if (!($entities = $this->normalizeEntities($entities))) {
            return;
        }

        foreach ($properties as $prop) {
            if (!$meta->hasAggregateProperty($prop)) {
                throw new \InvalidArgumentException("Entity {$meta->getEntityClass()} has no aggregate property '$prop'");
            } else {
                $this->loadAggregation($meta, $entities, $prop);
            }
        }
    }


    private function normalizeMeta($entity) : Metadata\Entity {
        if (is_string($entity)) {
            return $this->metadataRegistry->get($entity);
        } else if ($entity instanceof Metadata\Entity) {
            return $entity;
        } else {
            throw new \InvalidArgumentException("Invalid argument, expected a string or an instance of " . Metadata\Entity::class);
        }
    }


    private function normalizeEntities($entities) {
        if ($entities instanceof Lookup) {
            $entities = $entities->toArray();
        } else if (!is_array($entities)) {
            $entities = [$entities];
        }

        return array_filter($entities);
    }


    private function loadRelation(Metadata\Entity $meta, array $entities, string $relation) : array {
        $info = $meta->getRelationInfo($relation);
        $target = $this->metadataRegistry->get($info['target']);

        if (!empty($info['fk'])) {
            $identifier = $target->getSingleIdentifierProperty();

            if (!$identifier) {
                throw new \RuntimeException("Target entity {$target->getEntityClass()} of relation {$meta->getEntityClass()}#{$relation} has " . ($meta->getIdentifierProperties() ? 'a composite' : 'no') . ' identifier');
            }

            $ids = Helpers::extractPropertyFromEntities($meta, $entities, $info['fk'], true);

            if (!$ids) {
                return [];
            }

            $related = $this->find($target)
                ->where([
                    $identifier . ' in' => $ids,
                ])
                ->associateBy($identifier);

            foreach ($entities as $entity) {
                $fk = $meta->getReflection($info['fk'])->getValue($entity);

                if (isset($related[$fk])) {
                    $meta->getReflection($relation)->setValue($entity, $related[$fk]);
                }
            }

            return $related->toArray();

        } else if (!empty($info['collection'])) {
            if ($target->hasRelationTarget($meta->getEntityClass(), $relation)) {
                $targetProp = $target->getRelationTarget($meta->getEntityClass(), $relation);
                $inverse = $target->getRelationInfo($targetProp);
            } else {
                throw new \RuntimeException("Cannot determine inverse parameters of relation {$meta->getEntityClass()}#{$relation}");
            }

            if (!empty($inverse['collection'])) {
                throw new \RuntimeException("M:N relation loading has not been implemented yet");
            } else if (empty($inverse['fk'])) {
                throw new \RuntimeException("Relation {$target->getEntityClass()}#{$targetProp} does not specify a foreign key");
            } else {
                $identifier = $meta->getSingleIdentifierProperty();

                if (!$identifier) {
                    throw new \RuntimeException("Entity {$meta->getEntityClass()} has " . ($meta->getIdentifierProperties() ? 'a composite' : 'no') . ' identifier');
                }

                $ids = Helpers::extractPropertyFromEntities($meta, $entities, $identifier, true);

                if (!$ids) {
                    return [];
                }

                $related = $this->find($target)
                    ->where([
                        $inverse['fk'] . ' in' => $ids,
                    ])
                    ->associateBy($inverse['fk'] . '[]');

                if (!empty($info['orderBy'])) {
                    $related->orderBy($info['orderBy']);
                }

                $tmp = [];
                $rid = $meta->getReflection($identifier);
                $rrel = $meta->getReflection($relation);

                foreach ($entities as $entity) {
                    $id = $rid->getValue($entity);
                    $rrel->setValue($entity, $related[$id] ?? []);

                    if (!empty($related[$id])) {
                        array_push($tmp, ... $related[$id]);
                    }
                }

                return $tmp;
            }
        } else {
            return [];
        }
    }


    private function loadAggregation(Metadata\Entity $meta, array $entities, string $prop) : void {
        $info = $meta->getAggregatePropertyInfo($prop);
        $relation = $meta->getRelationInfo($info['relation']);
        $target = $this->metadataRegistry->get($relation['target']);

        if ($target->hasRelationTarget($meta->getEntityClass(), $info['relation'])) {
            $targetProp = $target->getRelationTarget($meta->getEntityClass(), $info['relation']);
            $inverse = $target->getRelationInfo($targetProp);
        } else {
            throw new \RuntimeException("Cannot determine inverse parameters of relation {$meta->getEntityClass()}#{$relation}");
        }

        $identifier = $meta->getSingleIdentifierProperty();

        if (!$identifier) {
            throw new \RuntimeException("Entity {$meta->getEntityClass()} has " . ($meta->getIdentifierProperties() ? 'a composite' : 'no') . ' identifier');
        }

        $ids = Helpers::extractPropertyFromEntities($meta, $entities, $identifier, true);

        if (!$ids) {
            return;
        }

        $fields = [
            '_id' => $inverse['fk'],
            '_value' => new Expression($info['type'] . '(_o.' . $info['property'] . ')'),
        ];

        $where = [
            $inverse['fk'] . ' in' => $ids,
        ];

        if (!empty($info['where'])) {
            $where[] = $info['where'];
        }

        $ast = $this->queryBuilder->buildSelectQuery($target, '_o', $fields, $where);
        $ast->groupBy[] = new SQL\AST\Node\Identifier('_o.' . $inverse['fk']);
        $query = $this->translator->compile($ast);
        $result = [];

        foreach ($this->execute($query) as $row) {
            $result[$row['_id']] = $row['_value'];
        }

        $rid = $meta->getReflection($identifier);
        $ragg = $meta->getReflection($prop);

        foreach ($entities as $entity) {
            $id = $rid->getValue($entity);
            $ragg->setValue($entity, $result[$id] ?? 0);
        }
    }

}
