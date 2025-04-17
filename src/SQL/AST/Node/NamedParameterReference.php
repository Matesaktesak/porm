<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class NamedParameterReference extends ParameterReference {

    /** @var string */
    public string $name;


    public function __construct(string $name, ?string $type = null, ?bool $nullable = null) {
        parent::__construct($type, $nullable);
        $this->name = $name;
    }

}
