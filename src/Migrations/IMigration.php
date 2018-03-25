<?php

declare(strict_types=1);

namespace PORM\Migrations;

use PORM\Drivers\IDriver;


interface IMigration {

    public function run(IDriver $driver) : void;

}
