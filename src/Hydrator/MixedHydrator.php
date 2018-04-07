<?php

declare(strict_types=1);

namespace PORM\Hydrator;

use PORM\EntityManager;
use PORM\Metadata;


class MixedHydrator {

    private $em;

    private $meta;

    private $entityKeys;


    public function __construct(EntityManager $em, Metadata\Entity $meta) {
        $this->em = $em;
        $this->meta = $meta;
        $this->entityKeys = array_fill_keys($meta->getProperties(), 1);
    }


    public function __invoke(array $row, string $resultId) : array {
        $entity = $this->em->hydrateEntity($this->meta, $row, $resultId);
        return [$entity] + array_diff_key($row, $this->entityKeys);
    }

}
