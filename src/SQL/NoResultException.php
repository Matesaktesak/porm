<?php

declare(strict_types=1);

namespace PORM\SQL;


class NoResultException extends QueryException {

    public function __construct(string $message = "No matching result(s) found", int $code = 0, ?string $query = null, ?array $parameters = null, \Throwable $previous = null) {
        parent::__construct($message, $code, $query, $parameters, $previous);
    }

}
