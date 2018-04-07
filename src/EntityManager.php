<?php

declare(strict_types=1);

namespace PORM;

use PORM\Hydrator\ArrayHydrator;
use PORM\Hydrator\MixedHydrator;
use PORM\SQL\Expression;


class EntityManager {

    private $connection;

    private $mapper;

    private $metadataProvider;

    private $translator;

    private $eventDispatcher;

    private $astBuilder;

    private $identityMap = [];



    public function __construct(
        Connection $connection,
        Mapper $mapper,
        Metadata\Provider $metadataProvider,
        SQL\Translator $translator,
        SQL\AST\Builder $astBuilder,
        EventDispatcher $eventDispatcher
    ) {
        $this->connection = $connection;
        $this->mapper = $mapper;
        $this->metadataProvider = $metadataProvider;
        $this->translator = $translator;
        $this->astBuilder = $astBuilder;
        $this->eventDispatcher = $eventDispatcher;
    }


    public function getEntityMetadata(string $entityClass) : Metadata\Entity {
        return $this->metadataProvider->get($entityClass);
    }


    public function get($entity, $id, bool $need = false) {
        $meta = $this->normalizeMeta($entity);
        $ast = $this->astBuilder->buildSelectQuery(
            $meta->getEntityClass(),
            null,
            $meta->getProperties(),
            $this->mapper->extractRawIdentifier($meta, $id)
        );

        $query = $this->translator->compile($ast);
        $result = $this->execute($query);

        if ($result && ($row = $result->fetch())) {
            return $this->hydrateEntity($meta, $row);
        } else if ($need) {
            throw new Exceptions\NoResultException();
        } else {
            return null;
        }
    }



    public function find($entity, ?array $where = null, $orderBy = null, ?int $limit = null, ?int $offset = null, ?string $associateBy = null) : Lookup {
        return new Lookup($this, $this->normalizeMeta($entity), $where, $orderBy, $limit, $offset, $associateBy);
    }


    public function persist($entity, $object) : void {
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

        if ($started = !$this->connection->inTransaction()) {
            $this->connection->beginTransaction();
        }

        try {
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

                $this->eventDispatcher->dispatch($meta->getEntityClass() . '::prePersist', $object, $this);

                $ast = $this->astBuilder->buildInsertQuery($meta->getEntityClass(), $meta->getPropertiesInfo(), $data);

                if ($genProp && $platform->supportsReturningClause()) {
                    $ast->returning = $this->astBuilder->buildResultFields([
                        '_generated' => $genProp,
                    ]);
                }

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

            } else if ($data) {
                $this->eventDispatcher->dispatch($meta->getEntityClass() . '::preUpdate', $object, $this, $changeset);

                $ast = $this->astBuilder->buildUpdateQuery($meta->getEntityClass(), null, $meta->getPropertiesInfo(), $data, $id);
                $query = $this->translator->compile($ast);
                $this->execute($query);

                $this->eventDispatcher->dispatch($meta->getEntityClass() . '::postUpdate', $object, $this, $changeset);
            }

            if ($prop = $meta->getSingleIdentifierProperty()) {
                $localId = $meta->getReflection($prop)->getValue($object);
            } else {
                return;
            }

            foreach ($meta->getRelationsInfo() as $relation => $info) {
                if (!empty($info['via'])) {
                    /** @var Collection $coll */
                    $coll = $meta->getReflection($relation)->getValue($object);
                    $remote = $this->getEntityMetadata($info['target']);
                    $remoteId = $remote->getReflection($remote->getSingleIdentifierProperty());

                    if ($add = $coll->getAddedEntries()) {
                        $add = array_map(function(object $entity) use ($info, $localId, $remoteId) : array {
                            return [
                                $info['via']['localColumn'] => $localId,
                                $info['via']['remoteColumn'] => $remoteId->getValue($entity),
                            ];
                        }, $add);

                        $ast = $this->astBuilder->buildInsertQuery($info['via']['table'], null, ... $add);
                        $query = $this->translator->compile($ast);
                        $this->execute($query);
                    }

                    if ($remove = $coll->getRemovedEntries()) {
                        $remove = array_map(function(object $entity) use ($remoteId) {
                            return $remoteId->getValue($entity);
                        }, $remove);

                        $ast = $this->astBuilder->buildDeleteQuery($info['via']['table'], null, [
                            $info['via']['localColumn'] => $localId,
                            $info['via']['remoteColumn'] . ' in' => $remove,
                        ]);

                        $query = $this->translator->compile($ast);
                        $this->execute($query);
                    }
                }
            }
        } catch (\Throwable $e) {
            if ($started) {
                $this->connection->rollback();
            }

            throw $e;
        }
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

            $ast = $this->astBuilder->buildDeleteQuery($meta->getEntityClass(), null, $id);
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
        $ast = $this->astBuilder->buildLookupSelectQuery($lookup);
        $query = $this->translator->compile($ast);

        $result = $this->execute($query);
        $meta = $lookup->getEntityMetadata();
        $result->addProcessor(new Hydrator\EntityHydrator($this, $meta));

        if ($relations = $lookup->getRelations()) {
            $result = iterator_to_array($result, false);
            $this->loadRelations($meta, $result, ... array_keys($relations));
        }

        if ($aggregate = $lookup->getAggregations()) {
            if (!is_array($result)) {
                $result = iterator_to_array($result);
            }

            $this->loadAggregate($meta, $result, ... $aggregate);
        }

        if ($associateBy = $lookup->getAssociateBy()) {
            $associateBy = preg_split('/\||(\[])/', $associateBy, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            $root = [];

            foreach ($result as $entity) {
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
            yield from $result;
        }
    }


    public function countLookup(Lookup $lookup) : int {
        $ast = $this->astBuilder->buildLookupSelectQuery($lookup, true);
        $query = $this->translator->compile($ast);
        $result = $this->execute($query);
        $count = $result ? $result->fetchSingle() : null;
        return $count !== null ? (int) $count : 0;
    }


    public function updateLookup(Lookup $lookup, array $data) : int {
        $ast = $this->astBuilder->buildLookupUpdateQuery($lookup, $data);
        $query = $this->translator->compile($ast);
        $this->execute($query);
        return $this->connection->getAffectedRows();
    }


    public function deleteLookup(Lookup $lookup) : int {
        $ast = $this->astBuilder->buildLookupDeleteQuery($lookup);
        $query = $this->translator->compile($ast);
        $this->execute($query);
        return $this->connection->getAffectedRows();
    }


    public function createQueryBuilder($entity = null, ?string $alias = null) : QueryBuilder {
        return new QueryBuilder($this->translator, $this->astBuilder, $entity ? $this->normalizeMeta($entity) : null, $alias);
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
            $result->addProcessor(new ArrayHydrator($this->mapper, $query->getResultMap()));
        }

        return $result;
    }


    public function nativeQuery(string $query, ?array $parameters = null) : ?SQL\ResultSet {
        return $this->connection->query($query, $parameters ? $this->mapper->convertToDb($parameters) : null);
    }


    public function hydrateEntity(Metadata\Entity $meta, array $data, ?string $resultId = null) : object {
        $id = $this->mapper->extractRawIdentifier($meta, $data);
        $hash = implode('|', $id);
        $class = $meta->getEntityClass();

        if (isset($this->identityMap[$class][$hash])) {
            $entity = $this->identityMap[$class][$hash]['object'];
            $new = false;

            if ($resultId && $resultId === $this->identityMap[$class][$hash]) {
                return $entity;
            }
        } else {
            $entity = $meta->getReflection()->newInstanceWithoutConstructor();
            $this->identityMap[$class][$hash]['object'] = $entity;
            $new = true;

            foreach ($meta->getRelationsInfo() as $relation => $info) {
                if (!empty($info['collection'])) {
                    $meta->getReflection($relation)->setValue($entity, new Collection());
                }
            }
        }

        $this->identityMap[$class][$hash]['data'] = $data;
        $this->identityMap[$class][$hash]['resultId'] = $resultId;
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
            return $this->metadataProvider->get($entity);
        } else if ($entity instanceof Metadata\Entity) {
            return $entity;
        } else {
            throw new \InvalidArgumentException("Invalid argument, expected a string or an instance of " . Metadata\Entity::class);
        }
    }


    private function normalizeEntities($entities) {
        if ($entities instanceof \Traversable) {
            $entities = iterator_to_array($entities, false);
        } else if (!is_array($entities)) {
            $entities = [$entities];
        }

        return array_filter($entities);
    }


    private function loadRelation(Metadata\Entity $meta, array $entities, string $relation) : array {
        $info = $meta->getRelationInfo($relation);
        $target = $this->metadataProvider->get($info['target']);
        $builder = $this->createQueryBuilder($target, '_r');

        if (!empty($info['fk'])) {
            $identifier = $target->getSingleIdentifierProperty();
            $collection = false;
            $mapBy = $identifier;
            $attachBy = $info['fk'];

            if (!$identifier) {
                throw new \RuntimeException("Target entity {$target->getEntityClass()} of relation {$meta->getEntityClass()}#{$relation} has " . ($meta->getIdentifierProperties() ? 'a composite' : 'no') . ' identifier');
            }

            $ids = Helpers::extractPropertyFromEntities($meta, $entities, $info['fk'], true);

            if (!$ids) {
                return [];
            }

            $where = [
                $identifier . ' in' => $ids,
            ];
        } else if (!empty($info['collection'])) {
            if ($target->hasRelationTarget($meta->getEntityClass(), $relation)) {
                $targetProp = $target->getRelationTarget($meta->getEntityClass(), $relation);
                $inverse = $target->getRelationInfo($targetProp);
            } else {
                throw new \RuntimeException("Cannot determine inverse parameters of relation {$meta->getEntityClass()}#{$relation}");
            }

            $identifier = $meta->getSingleIdentifierProperty();
            $attachBy = $identifier;
            $collection = true;

            if (!$identifier) {
                throw new \RuntimeException("Entity {$meta->getEntityClass()} has " . ($meta->getIdentifierProperties() ? 'a composite' : 'no') . ' identifier');
            }

            $ids = Helpers::extractPropertyFromEntities($meta, $entities, $identifier, true);

            if (!$ids) {
                return [];
            }

            if (!empty($info['via'])) {
                $builder->select(
                    $target->getProperties()
                    + [ '_xid' => '_x.' . $info['via']['localColumn'] ]
                );

                $builder->innerJoin($info['via']['table'], '_x', [
                    '_x.' . $info['via']['remoteColumn'] => new Expression('_r.' . $target->getSingleIdentifierProperty()),
                ]);

                $where = [
                    '_x.' . $info['via']['localColumn'] . ' in' => $ids,
                ];

                $mapBy = '_xid';
            } else {
                $where = [
                    $inverse['fk'] . ' in' => $ids,
                ];

                $mapBy = $inverse['fk'];
            }

            if (!empty($info['orderBy'])) {
                $builder->orderBy($info['orderBy']);
            }
        } else {
            return [];
        }

        if (!empty($info['where'])) {
            $where[] = $info['where'];
        }

        $builder->where($where);

        $result = $this->execute($builder->getQuery());
        $result->addProcessor(new MixedHydrator($this, $target));
        $iprop = $meta->getReflection($attachBy);
        $rprop = $meta->getReflection($relation);
        $map = [];
        $related = [];

        foreach ($result as $row) {
            $related[] = $row[0];

            if ($collection) {
                $map[$row[$mapBy] ?? $row[0]->$mapBy][] = $row[0];
            } else {
                $map[$row[$mapBy] ?? $row[0]->$mapBy] = $row[0];
            }
        }

        foreach ($entities as $entity) {
            $id = $iprop->getValue($entity);

            if ($collection) {
                $rprop->getValue($entity)->merge($map[$id] ?? []);
            } else {
                $rprop->setValue($entity, $map[$id] ?? null);
            }
        }

        return $related;
    }


    private function loadAggregation(Metadata\Entity $meta, array $entities, string $prop) : void {
        $info = $meta->getAggregatePropertyInfo($prop);
        $relation = $meta->getRelationInfo($info['relation']);
        $target = $this->metadataProvider->get($relation['target']);

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

        $builder = $this->createQueryBuilder();

        if (!empty($inverse['collection'])) {
            if ($info['property'] === $target->getSingleIdentifierProperty() && empty($info['where'])) {
                $builder->select([
                    '_id' => '_x.' . $inverse['via']['remoteColumn'],
                    '_value' => new Expression($info['type'] . '(_x.' . $inverse['via']['localColumn'] . ')'),
                ]);

                $builder->from($inverse['via']['table'], '_x');
            } else {
                $builder->select([
                    '_id' => '_x.' . $inverse['via']['remoteColumn'],
                    '_value' => new Expression($info['type'] . '(_o.' . $info['property'] . ')'),
                ]);

                $builder->from($inverse['via']['table'], '_x');
                $builder->innerJoin($relation['target'], '_o', [
                    '_o.' . $target->getSingleIdentifierProperty() => new Expression('_x.' . $inverse['via']['localColumn']),
                ]);
            }

            $builder->groupBy(['_x.' . $inverse['via']['remoteColumn']]);

            $where = [
                '_x.' . $inverse['via']['remoteColumn'] . ' in' => $ids,
            ];
        } else {
            $builder->select([
                '_id' => '_o.' . $inverse['fk'],
                '_value' => new Expression($info['type'] . '(_o.' . $info['property'] . ')'),
            ]);

            $builder->from($relation['target'], '_o');
            $builder->groupBy(['_o.' . $inverse['fk']]);

            $where = [
                '_o.' . $inverse['fk'] . ' in' => $ids,
            ];
        }

        if (!empty($info['where'])) {
            $where[] = $info['where'];
        }

        $builder->where($where);
        $result = [];

        foreach ($this->execute($builder->getQuery()) as $row) {
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
