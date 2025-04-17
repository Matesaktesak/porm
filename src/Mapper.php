<?php

declare(strict_types=1);

namespace PORM;


class Mapper {

    private Drivers\IPlatform $platform;


    public function __construct(Drivers\IPlatform $platform) {
        $this->platform = $platform;
    }


    public function extractIdentifier(Metadata\Entity $meta, $entity): ?array {
        $properties = $meta->getIdentifierProperties();

        if (empty($properties)) {
            throw new \LogicException("Entity " . $meta->getEntityClass() . " has no identifier properties");
        }

        return $this->extract($meta, $entity, $properties);
    }

    public function extractRawIdentifier(Metadata\Entity $meta, $id): array {
        $properties = $meta->getIdentifierProperties();

        if (count($properties) === 1) {
            $prop = reset($properties);

            if (!is_array($id)) {
                return [$prop => $id];
            } else if (!isset($id[$prop])) {
                throw new \InvalidArgumentException("Invalid identifier, missing property $prop");
            }
        }

        if (!is_array($id)) {
            throw new \InvalidArgumentException("Invalid argument, expected an array, got " . gettype($id));
        } else if ($missing = array_diff($properties, array_keys($id))) {
            throw new \InvalidArgumentException("Invalid composite identifier, missing properties '" . implode("', '", array_keys($missing)) . "'");
        }

        $tmp = [];

        foreach ($properties as $prop) {
            $tmp[$prop] = $id[$prop];
        }

        return $tmp;
    }

    public function extract(Metadata\Entity $meta, $entity, ?array $properties = null): array {
        if (!$meta->getReflection()->isInstance($entity)) {
            throw new \InvalidArgumentException("Entity is not managed by this EntityManager");
        }

        if (!$properties) {
            $properties = $meta->getProperties();
        }

        $data = [];

        foreach ($properties as $prop) {
            $data[$prop] = $meta->getReflection($prop)->getValue($entity);
        }

        return $data;
    }

    public function hydrate(Metadata\Entity $meta, $entity, array $data): array {
        foreach ($meta->getProperties() as $prop) {
            $meta->getReflection($prop)->setValue($entity, $data[$prop] ?? null);
        }

        return $data;
    }

    public function convertFromDb(array $data, ?array $info = null): array {
        foreach ($data as $key => $value) {
            $nfo = $info[$key] ?? null;
            $data[$key] = $this->convertFromDbType($value, $nfo['type'] ?? null, $nfo['nullable'] ?? null);
        }

        return $data;
    }

    public function convertToDb(array $data, ?array $info = null): array {
        foreach ($data as $key => $value) {
            $nfo = $info[$key] ?? null;
            $data[$key] = $this->convertToDbType($value, $nfo['type'] ?? null, $nfo['nullable'] ?? null);
        }

        return $data;
    }


    public function convertFromDbType($value, ?string $type = null, ?bool $nullable = null) {
        if ($this->returnNull($value, $nullable)) {
            return null;
        }

        switch ($type ?? $this->guessType($value)) {
            case 'string':
                return (string)$value;
            case 'int':
                return (int)$value;
            case 'float':
                return (float)$value;
            case 'bool':
                return $this->platform->fromPlatformBool($value);
            case 'date':
                return $this->platform->fromPlatformDate($value);
            case 'time':
                return $this->platform->fromPlatformTime($value);
            case 'datetime':
                return $this->platform->fromPlatformDateTime($value);
            case 'json':
                return json_decode($value, true);
            default:
                throw new \InvalidArgumentException("Unknown property type");
        }
    }

    public function convertToDbType($value, ?string $type = null, ?bool $nullable = null): float|false|int|string|null {
        if ($this->returnNull($value, $nullable)) {
            return null;
        }

        return match ($type ?? $this->guessType($value)) {
            null, 'string' => (string)$value,
            'int' => (int)$value,
            'float' => (float)$value,
            'bool' => $this->platform->toPlatformBool($value),
            'date' => $this->platform->toPlatformDate($value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value)),
            'time' => $this->platform->toPlatformTime($value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value)),
            'datetime' => $this->platform->toPlatformDateTime($value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value)),
            'json' => json_encode($value),
            default => throw new \InvalidArgumentException("Unknown property type '$type'"),
        };
    }

    private function guessType($value): ?string {
        switch (true) {
            case is_int($value):
                return 'int';
            case is_float($value):
                return 'float';
            case is_bool($value):
                return 'bool';
            case is_array($value):
                return 'json';
            case $value instanceof \DateTimeInterface:
                return 'datetime';
            case is_string($value):
                if (preg_match('~^(?:(?<n>(?:[1-9]\d*|\d)(?<f>\.\d+)?)|(?<d>\d\d\d\d-\d\d-\d\d)|(?<t>\d\d:\d\d(?::\d\d)?)|(?<dt>(?&d) (?&t)))$~', $value, $m)) {
                    switch (true) {
                        case isset($m['f']) && $m['f'] !== '':
                            return 'float';
                        case isset($m['n']) && $m['n'] !== '':
                            return 'int';
                        case isset($m['d']) && $m['d'] !== '':
                            return 'date';
                        case isset($m['t']) && $m['t'] !== '':
                            return 'time';
                        case isset($m['dt']) && $m['dt'] !== '':
                            return 'datetime';
                    }
                }

                return 'string';
        }

        return null;
    }

    private function returnNull($value, ?bool $nullable): bool {
        if ($value === null) {
            if ($nullable !== false) {
                return true;
            } else {
                throw new \InvalidArgumentException("Invalid null value");
            }
        } else {
            return false;
        }
    }
}
