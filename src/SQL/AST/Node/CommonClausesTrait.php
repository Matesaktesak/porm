<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


trait CommonClausesTrait {

    /** @var OrderExpression[] */
    public $orderBy = [];

    /** @var Expression|null */
    public $limit = null;

    /** @var Expression|null */
    public $offset = null;

}
