<?php

declare(strict_types=1);

namespace PORM\Migrations;


/**
 * @property-read int $version
 * @property-read string $type
 */
class Migration {

    const PHP = 'php',
        SQL = 'sql';

    private int $version;

    private string $type;


    public function __construct(int $version, string $type) {
        $this->version = $version;
        $this->type = $type;
    }


    public function getVersion() : int {
        return $this->version;
    }

    public function getType() : string {
        return $this->type;
    }


    public function __get(string $name) {
        if ($name === 'version' || $name === 'type') {
            return $this->{'get' . ucfirst($name)}();
        } else {
            throw new \RuntimeException("Undefined property: " . static::class . "::\$$name");
        }
    }
}
