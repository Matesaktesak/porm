<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


trait ReturningClauseTrait {

    /** @var ResultField[] */
    public $returning = [];

}
