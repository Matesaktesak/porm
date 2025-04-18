<?php

declare(strict_types=1);

namespace PORM\SQL;


class Event {

    private string $query;

    private ?array $parameters;

    private $startTime;

    private $endTime;

    /** @var ResultSet */
    private ResultSet $resultSet;

    private ?int $affectedRows;

    public $file;
    public $line;


    public function __construct(string $query, ?array $parameters = null) {
        $this->query = $query;
        $this->parameters = $parameters;
    }


    public function start() : void {
        $this->startTime = microtime(true);
    }

    public function end() : void {
        $this->endTime = microtime(true);
    }

    public function setResultSet(ResultSet $resultSet) : void {
        $this->resultSet = $resultSet;
    }

    public function setAffectedRows(int $affectedRows) : void {
        $this->affectedRows = $affectedRows;
    }


    public function getQuery() : string {
        return $this->query;
    }

    public function hasParameters() : bool {
        return !empty($this->parameters);
    }

    public function getParameters() : ?array {
        return $this->parameters;
    }

    public function getDuration() : ?float {
        $queryDuration = isset($this->startTime, $this->endTime) ? $this->endTime - $this->startTime : null;
        $fetchDuration = $this->resultSet?->getFetchDuration();
        return isset($queryDuration) || isset($fetchDuration) ? ($queryDuration ?? 0) + ($fetchDuration ?? 0) : null;
    }

    public function getAffectedRows() : ?int {
        return $this->affectedRows;
    }

    public function getFetchedRows() : ?int {
        return $this->resultSet?->getFetchedRows();
    }
}
