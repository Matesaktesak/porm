<?php

declare(strict_types=1);

namespace PORM\Migrations;

use PORM\Drivers\IDriver;
use PORM\Drivers\IPlatform;


class Resolver {

    private IDriver $driver;

    private IPlatform $platform;

    private string $migrationsDir;


    public function __construct(IDriver $driver, IPlatform $platform, string $migrationsDir) {
        $this->driver = $driver;
        $this->platform = $platform;
        $this->migrationsDir = $migrationsDir;
    }


    /**
     * @return Migration[]
     */
    public function getNewMigrations() : array {
        $applied = $this->findAppliedMigrations();
        $available = $this->findAvailableMigrations();
        $new = [];

        foreach ($available as $migration) {
            if (!isset($applied[$migration->version])) {
                $new[] = $migration;
            }
        }

        return $new;
    }

    public function getPath(Migration $migration) : string {
        return $this->migrationsDir . '/Migration_' . $migration->version . '.' . $migration->type;
    }

    public function markAsApplied(Migration $migration) : void {
        $this->platform->markMigrationApplied($this->driver, $migration);
    }


    /**
     * @return Migration[]
     */
    private function findAppliedMigrations() : array {
        $migrations = $this->platform->getAppliedMigrations($this->driver);
        $map = [];

        foreach ($migrations as $migration) {
            $map[$migration->version] = $migration;
        }

        return $map;
    }

    /**
     * @return Migration[]
     */
    private function findAvailableMigrations() : array {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }

        $it = new \FilesystemIterator($this->migrationsDir, \FilesystemIterator::CURRENT_AS_PATHNAME);
        $filt = new \RegexIterator($it, '~/Migration_(\d+)\.(sql|php)$~', \RegexIterator::GET_MATCH);

        $migrations = array_map(function(array $m) : Migration {
            return new Migration((int) $m[1], $m[2]);
        }, iterator_to_array($filt));

        usort($migrations, function(Migration $a, Migration $b) : int {
            return $a->getVersion() - $b->getVersion();
        });

        return $migrations;
    }

}
