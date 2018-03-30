<?php

declare(strict_types=1);

namespace PORM\Drivers;

use PORM\Migrations\Migration;
use PORM\SQL\AST\Node as AST;
use PORM\SQL\Expression;
use PORM\SQL\Query;


interface IPlatform {

    public function supportsReturningClause() : bool;


    public function formatGenerator(string $name, bool $increment = true) : Expression;

    public function formatSelectQuery(AST\SelectQuery $query) : Query;

    public function formatInsertQuery(AST\InsertQuery $query) : Query;

    public function formatUpdateQuery(AST\UpdateQuery $query) : Query;

    public function formatDeleteQuery(AST\DeleteQuery $query) : Query;


    public function toPlatformBool(bool $value);

    public function toPlatformDate(\DateTimeInterface $date) : string;

    public function toPlatformTime(\DateTimeInterface $time) : string;

    public function toPlatformDateTime(\DateTimeInterface $datetime) : string;

    public function fromPlatformBool($value) : bool;

    public function fromPlatformDate(string $date) : \DateTimeImmutable;

    public function fromPlatformTime(string $time) : \DateTimeImmutable;

    public function fromPlatformDateTime(string $datetime) : \DateTimeImmutable;


    /**
     * @param IDriver $driver
     * @return Migration[]
     */
    public function getAppliedMigrations(IDriver $driver) : array;

    public function markMigrationApplied(IDriver $driver, Migration $migration) : void;

}
