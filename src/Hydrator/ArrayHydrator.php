<?php

declare(strict_types=1);

namespace PORM\Hydrator;

use PORM\Mapper;


class ArrayHydrator {

    private $mapper;

    private $resultMap;



    public function __construct(Mapper $mapper, array $resultMap) {
        $this->mapper = $mapper;
        $this->resultMap = $resultMap;
    }


    public function __invoke(array $row) : array {
        $result = [];

        foreach ($row as $key => $value) {
            $info = $this->resultMap[$key] ?? null;

            $result[$info['alias'] ?? $key]
                = $this->mapper->convertFromDbType($value, $info['type'] ?? null, $info['nullable'] ?? null);
        }

        return $result;
    }

}
