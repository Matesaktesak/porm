<?php

namespace PORM\DI;

class FactoryConfiguration {
    public array $connection = [
        'platform' => null,
    ];
    public array $entities = [];
    public string $namingStrategy = 'snakeCase';
    public ?string $migrationsDir;
    public ?bool $debugger;
}
