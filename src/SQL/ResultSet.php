<?php

declare(strict_types=1);

namespace PORM\SQL;

use PORM\Drivers\IDriver;
use PORM\Exception;


class ResultSet implements \IteratorAggregate {

    private IDriver $driver;

    private $resource;

    private array $processors = [];

    private int $fetchedRows = 0;

    private $fetchStartTime = null;

    private $fetchEndTime = null;

    /** @var string */
    private ?string $resultId = null;


    public function __construct(IDriver $driver, $resource) {
        $this->driver = $driver;
        $this->resource = $resource;
    }


    public function addProcessor(callable $processor) : void {
        $this->processors[] = $processor;
    }


    public function fetch() {
        $this->assertResourceNotFreed();

        if (!isset($this->fetchStartTime)) {
            $this->fetchStartTime = microtime(true);
        }

        $row = $this->driver->fetchRow($this->resource);

        if (!isset($this->fetchEndTime)) {
            $this->fetchEndTime = microtime(true);
        }

        if ($row !== null) {
            $this->fetchedRows++;

            foreach ($this->processors as $processor) {
                $row = call_user_func($processor, $row, $this->getResultId());
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

    public function getFetchDuration() : ?float {
        return isset($this->fetchStartTime) && isset($this->fetchEndTime)
            ? $this->fetchEndTime - $this->fetchStartTime
            : null;
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


    private function getResultId() : string {
        return $this->resultId ?? $this->resultId = spl_object_hash($this);
    }


    private function assertResourceNotFreed() : void {
        if ($this->resource === null) {
            throw new Exception("Result set has already been freed");
        }
    }


    public function __destruct() {
        $this->free();
    }
}
