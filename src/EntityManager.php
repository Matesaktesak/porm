<?php

declare(strict_types=1);

namespace PORM;


use PORM\Exceptions\InvalidQueryException;
use PORM\Exceptions\NoResultException;
use PORM\Metadata\Provider;
use ReflectionException;

class EntityManager {

    private Connection $connection;

    private Mapper $mapper;

    private Metadata\Provider $metadataProvider;

    private SQL\Translator $translator;

    private EventDispatcher $eventDispatcher;

    private SQL\AST\Builder $astBuilder;

    private array $identityMap = [];

    public function __construct(
        Connection        $connection,
        Mapper            $mapper,
        Metadata\Provider $metadataProvider,
        SQL\Translator    $translator,
        SQL\AST\Builder   $astBuilder,
        EventDispatcher   $eventDispatcher
    ) {
        $this->connection = $connection;
        $this->mapper = $mapper;
        $this->metadataProvider = $metadataProvider;
        $this->translator = $translator;
        $this->astBuilder = $astBuilder;
        $this->eventDispatcher = $eventDispatcher;
    }


    public function getEntityMetadata(string $entityClass): Metadata\Entity {
        return $this->metadataProvider->get($entityClass);
    }


    /**
     * @throws NoResultException
     * @throws InvalidQueryException
     * @throws ReflectionException
     */
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


    public function find($entity, ?string $alias = null): Lookup {
        return new Lookup($this, $this->normalizeMeta($entity), $alias);
    }


    public function persist($entity, $object): void {
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
            if (!isset($orig[$prop]) && !key_exists($prop, $orig) || $orig[$prop] !== $value) {
                $changeset[$prop] = [$orig[$prop] ?? null, $value];
            } else {
                unset($data[$prop]);
            }
        }

        $id = array_filter($id, function ($v) {
            return $v !== null;
        });

        if (!empty($id) && !$meta->hasGeneratedProperty() && !isset($this->identityMap[$meta->getEntityClass()][$hash])) {
            $data = $id + $data;
            $id = null;
        }

        if ($started = !$this->connection->inTransaction()) {
            $this->connection->beginTransaction();
        }

        $new = empty($id);

        try {
            if ($new) {
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
                        $id = (int)$result->fetchSingle();
                    } else {
                        $id = $driver->getLastGeneratedValue($genPropInfo['generator']);
                    }

                    $meta->getReflection($genProp)->setValue($object, $id);
                }

                $this->attach($meta, $object);

                $this->persistRelations($meta, $object, true);

                $this->eventDispatcher->dispatch($meta->getEntityClass() . '::postPersist', $object, $this);

            } else {
                if ($data) {
                    $this->eventDispatcher->dispatch($meta->getEntityClass() . '::preUpdate', $object, $this, $changeset);

                    $ast = $this->astBuilder->buildUpdateQuery($meta->getEntityClass(), null, $meta->getPropertiesInfo(), $data, $id);
                    $query = $this->translator->compile($ast);
                    $this->execute($query);

                    $this->identityMap[$meta->getEntityClass()][$hash]['data'] = $data + $orig;
                }

                $related = $this->persistRelations($meta, $object, false);

                if ($data || $related) {
                    $this->eventDispatcher->dispatch($meta->getEntityClass() . '::postUpdate', $object, $this, $changeset);
                }
            }

            if ($started) {
                $this->connection->commit();
            }
        } catch (\Throwable $e) {
            if ($started) {
                $this->connection->rollback();
            }

            throw $e;
        }
    }

    public function remove($entity, $object): self {
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


    public function beginTransaction(): self {
        $this->connection->beginTransaction();
        return $this;
    }

    public function commit(): self {
        $this->connection->commit();
        return $this;
    }

    public function rollback(): self {
        $this->connection->rollback();
        return $this;
    }

    public function transactional(callable $callback, ...$args) {
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


    public function createQueryBuilder($entity = null, ?string $alias = null): QueryBuilder {
        return new QueryBuilder($this->translator, $this->astBuilder, $entity ? $this->normalizeMeta($entity) : null, $alias);
    }


    public function expr(string $sql, ?array $parameters = null): SQL\Expression {
        return new SQL\Expression($sql, $parameters);
    }


    public function query(string $query, ?array $parameters = null): ?SQL\ResultSet {
        $query = $this->translator->translate($query);

        if ($parameters) {
            $query->setParameters($parameters);
        }

        return $this->execute($query);
    }

    public function execute(SQL\Query $query, ?Metadata\Entity $entity = null): ?SQL\ResultSet {
        $result = $this->connection->query($query->getSql(), $this->mapper->convertToDb($query->getParameters(), $query->getParameterMap()));

        if ($result) {
            $result->addProcessor(new Hydrator\ArrayHydrator($this->mapper, $query->getResultMap()));

            if ($entity) {
                $result->addProcessor(new Hydrator\EntityHydrator($this, $entity));
            }
        }

        return $result;
    }


    public function nativeQuery(string $query, ?array $parameters = null): ?SQL\ResultSet {
        return $this->connection->query($query, $parameters ? $this->mapper->convertToDb($parameters) : null);
    }


    /**
     * @throws ReflectionException
     */
    public function hydrateEntity(Metadata\Entity $meta, array $data, ?string $resultId = null): object {
        $id = $this->mapper->extractRawIdentifier($meta, $data);
        $hash = implode('|', $id);
        $class = $meta->getEntityClass();

        if (isset($this->identityMap[$class][$hash])) {
            $entity = $this->identityMap[$class][$hash]['object'];
            $new = false;

            if ($resultId && $resultId === $this->identityMap[$class][$hash]['resultId']) {
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


    public function isAttached(Metadata\Entity $meta, $entity): bool {
        $id = $this->mapper->extractIdentifier($meta, $entity);
        $hash = implode('|', $id);
        return isset($this->identityMap[$meta->getEntityClass()][$hash]);
    }

    public function attach(Metadata\Entity $meta, $entity): void {
        $id = $this->mapper->extractIdentifier($meta, $entity);
        $hash = implode('|', $id);
        $class = $meta->getEntityClass();

        if (isset($this->identityMap[$class][$hash])) {
            if ($this->identityMap[$class][$hash]['object'] !== $entity) {
                throw new Exception("Identity map violation: another instance of entity {$class}#{$hash} is already attached");
            }
        } else {
            $this->identityMap[$class][$hash] = [
                'resultId' => null,
                'object' => $entity,
                'data' => [],
            ];
        }
    }

    public function detach(Metadata\Entity $meta, $entity): void {
        $id = $this->mapper->extractIdentifier($meta, $entity);
        $hash = implode('|', $id);
        unset($this->identityMap[$meta->getEntityClass()][$hash]);
    }


    public function loadRelations(Metadata\Entity $meta, $entities, string ...$relations): void {
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


    public function loadAggregate(Metadata\Entity $meta, $entities, string ...$properties): void {
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


    private function normalizeMeta($entity): Metadata\Entity {
        if (is_string($entity)) {
            return $this->metadataProvider->get($entity);
        } else if ($entity instanceof Metadata\Entity) {
            return $entity;
        } else {
            throw new \InvalidArgumentException("Invalid argument, expected a string or an instance of " . Metadata\Entity::class);
        }
    }


    private function normalizeEntities($entities): array {
        if ($entities instanceof \Traversable) {
            $entities = iterator_to_array($entities, false);
        } else if (!is_array($entities)) {
            $entities = [$entities];
        }

        return array_filter($entities);
    }


    /**
     * @throws InvalidQueryException
     */
    private function persistRelations(Metadata\Entity $meta, object $object, bool $new): bool {
        if ($prop = $meta->getSingleIdentifierProperty(false)) {
            $localId = $meta->getReflection($prop)->getValue($object);
        } else {
            return false;
        }

        $updated = false;

        foreach ($meta->getRelationsInfo() as $relation => $info) {
            if (!empty($info['via'])) {
                /** @var Collection $coll */
                $coll = $meta->getReflection($relation)->getValue($object);
                $remote = $this->getEntityMetadata($info['target']);
                $remoteId = $remote->getReflection($remote->getSingleIdentifierProperty());

                if ($add = ($new ? $coll->toArray() : $coll->getAddedEntries())) {
                    $add = array_map(function (object $entity) use ($info, $localId, $remoteId): array {
                        return [
                            $info['via']['localColumn'] => $localId,
                            $info['via']['remoteColumn'] => $remoteId->getValue($entity),
                        ];
                    }, $add);

                    $ast = $this->astBuilder->buildInsertQuery($info['via']['table'], null, ... $add);
                    $query = $this->translator->compile($ast);
                    $this->execute($query);
                    $updated = true;
                }

                if ($remove = $coll->getRemovedEntries()) {
                    $remove = array_map(function (object $entity) use ($remoteId) {
                        return $remoteId->getValue($entity);
                    }, $remove);

                    $ast = $this->astBuilder->buildDeleteQuery($info['via']['table'], null, [
                        $info['via']['localColumn'] => $localId,
                        $info['via']['remoteColumn'] . ' in' => $remove,
                    ]);

                    $query = $this->translator->compile($ast);
                    $this->execute($query);
                    $updated = true;
                }
            }
        }

        return $updated;
    }


    /**
     * @throws InvalidQueryException
     */
    private function loadRelation(Metadata\Entity $meta, array $entities, string $relation): array {
        $info = $meta->getRelationInfo($relation);
        $target = $this->metadataProvider->get($info['target']);
        $builder = $this->createQueryBuilder($target, '_r');

        if (!empty($info['fk'])) {
            $identifier = $target->getSingleIdentifierProperty();
            $collection = false;
            $mapBy = $identifier;
            $attachBy = $info['fk'];

            $ids = Helpers::extractPropertyFromEntities($meta, $entities, $info['fk'], true);

            if (!$ids) {
                return [];
            }

            $where = [
                $identifier . ' in' => $ids,
            ];
        } else {
            if ($target->hasRelationTarget($meta->getEntityClass(), $relation)) {
                $targetProp = $target->getRelationTarget($meta->getEntityClass(), $relation);
                $inverse = $target->getRelationInfo($targetProp);
            } else {
                throw new \RuntimeException("Cannot determine inverse parameters of relation {$meta->getEntityClass()}#{$relation}");
            }

            $identifier = $meta->getSingleIdentifierProperty();
            $attachBy = $identifier;
            $collection = !empty($info['collection']);

            $ids = Helpers::extractPropertyFromEntities($meta, $entities, $identifier, true);

            if (!$ids) {
                return [];
            }

            if (!empty($info['via'])) {
                $builder->select(
                    $target->getProperties()
                    + ['_xid' => '_x.' . $info['via']['localColumn']]
                );

                $builder->innerJoin($info['via']['table'], '_x', [
                    '_x.' . $info['via']['remoteColumn'] => new SQL\Expression('_r.' . $target->getSingleIdentifierProperty()),
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
        }

        if (!empty($info['where'])) {
            $where[] = $info['where'];
        }

        $builder->where($where);

        $result = $this->execute($builder->getQuery());
        $result->addProcessor(new Hydrator\MixedHydrator($this, $target));
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


    /**
     * @throws InvalidQueryException
     */
    private function loadAggregation(Metadata\Entity $meta, array $entities, string $prop): void {
        $info = $meta->getAggregatePropertyInfo($prop);
        $relation = $meta->getRelationInfo($info['relation']);
        $target = $this->metadataProvider->get($relation['target']);

        if ($target->hasRelationTarget($meta->getEntityClass(), $info['relation'])) {
            $targetProp = $target->getRelationTarget($meta->getEntityClass(), $info['relation']);
            $inverse = $target->getRelationInfo($targetProp);
        } else {
            throw new \RuntimeException("Cannot determine inverse parameters of relation {$meta->getEntityClass()}#{$info['relation']}");
        }

        $identifier = $meta->getSingleIdentifierProperty();
        $ids = Helpers::extractPropertyFromEntities($meta, $entities, $identifier, true);

        if (!$ids) {
            return;
        }

        $builder = $this->createQueryBuilder();

        if (!empty($inverse['collection'])) {
            $tid = $target->getSingleIdentifierProperty();

            if ($info['property'] === $tid && empty($info['where'])) {
                $builder->select([
                    '_id' => '_x.' . $inverse['via']['remoteColumn'],
                    '_value' => new SQL\Expression($info['type'] . '(_x.' . $inverse['via']['localColumn'] . ')'),
                ]);

                $builder->from($inverse['via']['table'], '_x');
            } else {
                $builder->select([
                    '_id' => '_x.' . $inverse['via']['remoteColumn'],
                    '_value' => new SQL\Expression($info['type'] . '(_o.' . $info['property'] . ')'),
                ]);

                $builder->from($inverse['via']['table'], '_x');
                $builder->innerJoin($relation['target'], '_o', [
                    '_o.' . $tid => new SQL\Expression('_x.' . $inverse['via']['localColumn']),
                ]);
            }

            $builder->groupBy(['_x.' . $inverse['via']['remoteColumn']]);

            $where = [
                '_x.' . $inverse['via']['remoteColumn'] . ' in' => $ids,
            ];
        } else {
            $builder->select([
                '_id' => '_o.' . $inverse['fk'],
                '_value' => new SQL\Expression($info['type'] . '(_o.' . $info['property'] . ')'),
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
