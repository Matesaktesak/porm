<?php

declare(strict_types=1);

namespace PORM\Hydrator;

use PORM\EntityManager;
use PORM\Metadata;


class EntityHydrator {

    private EntityManager $em;

    private Metadata\Entity $meta;


    public function __construct(EntityManager $em, Metadata\Entity $meta) {
        $this->em = $em;
        $this->meta = $meta;
    }


    public function __invoke(array $row, string $resultId) : object {
        return $this->em->hydrateEntity($this->meta, $row, $resultId);
    }

}
