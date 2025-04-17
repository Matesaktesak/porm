<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


trait WhereClauseTrait {

    /** @var Expression|null */
    public ?Expression $where = null;

}
