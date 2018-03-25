<?php

declare(strict_types=1);

namespace PORM\Drivers;

use PORM\SQL\ResultSet;


interface IDriver {

    public function isConnected() : bool;

    public function connect() : void;

    public function disconnect() : void;

    public function getLastGeneratedValue(string $name) : int;

    public function query(string $query, ?array $parameters = null) : ?ResultSet;

    public function getAffectedRows() : int;

    public function fetchRow($resource) : ?array;

    public function freeResult($resource) : void;

    public function inTransaction() : bool;

    public function beginTransaction() : void;

    public function commit() : void;

    public function rollback() : void;

}
