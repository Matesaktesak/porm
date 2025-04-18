<?php

declare(strict_types=1);

namespace PORM\Exceptions;


class QueryException extends DriverException {

    private ?string $query;

    private ?array $parameters;


    public function __construct(string $message = "", int $code = 0, ?string $query = null, ?array $parameters = null, \Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->query = $query;
        $this->parameters = $parameters;
    }

    public function hasQuery() : bool {
        return isset($this->query);
    }

    public function getQuery() : ?string {
        return $this->query;
    }

    public function hasParameters() : bool {
        return !empty($this->parameters);
    }

    public function getParameters() : ?array {
        return $this->parameters;
    }

}
