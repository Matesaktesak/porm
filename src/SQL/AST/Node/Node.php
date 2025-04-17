<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


abstract class Node {

    public array $attributes = [];


    public function getTraversableProperties() : array {
        return [];
    }

}
