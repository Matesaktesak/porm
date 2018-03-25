<?php

declare(strict_types=1);

namespace PORM\SQL;

use PORM\Drivers\IDriver;
use PORM\Exception;


class ResultSet implements \IteratorAggregate {

    private $driver;

    private $resource;

    private $fieldMap = null;

    private $fetchedRows = 0;


    public function __construct(IDriver $driver, $resource) {
        $this->driver = $driver;
        $this->resource = $resource;
    }


    public function setFieldMap(array $map) : void {
        $this->fieldMap = $map;
    }


    public function fetch() : ?array {
        $this->assertResourceNotFreed();
        $row = $this->driver->fetchRow($this->resource);

        if ($row !== null) {
            $this->fetchedRows++;

            if ($this->fieldMap) {
                $row = $this->mapRowKeys($row, $this->fieldMap);
            }
        }

        return $row;
    }

    public function fetchSingle() {
        $row = $this->fetch();
        return $row !== null ? reset($row) : null;
    }

    public function getFetchedRows() : int {
        return $this->fetchedRows;
    }


    public function free() : self {
        if ($this->resource) {
            $this->driver->freeResult($this->resource);
            $this->resource = null;
        }

        return $this;
    }


    public function getIterator() : \Generator {
        try {
            while (($row = $this->fetch()) !== null) {
                yield $row;
            }
        } finally {
            $this->free();
        }
    }


    private function assertResourceNotFreed() : void {
        if ($this->resource === null) {
            throw new Exception("Result set has already been freed");
        }
    }

    private function mapRowKeys(array $row, array $map) : array {
        $mapped = [];

        foreach ($map as $prop => $info) {
            $mapped[$prop] = $row[$info['field']];
        }

        return $mapped;
    }


    public function __destruct() {
        $this->free();
    }
}
