<?php

declare(strict_types=1);

namespace PORM\SQL;


class Expression {

    private string $sql;

    private array $parameters;


    public function __construct(string $sql, ?array $parameters = null) {
        $this->sql = $sql;
        $this->parameters = $parameters ?: [];
    }

    public function getSql() : string {
        return $this->sql;
    }

    public function hasParameters() : bool {
        return !empty($this->parameters);
    }

    public function getParameters() : array {
        return $this->parameters;
    }

}
