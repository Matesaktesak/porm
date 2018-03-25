<?php

declare(strict_types=1);

namespace PORM;


class Helpers {

    public static function extractPropertyFromEntities(Metadata\Entity $meta, array $entities, string $property, bool $unique = false) : array {
        $reflection = $meta->getReflection($property);
        $data = [];

        foreach ($entities as $entity) {
            $data[] = $reflection->getValue($entity);
        }

        return $unique ? array_unique(array_filter($data)) : $data;
    }

}
