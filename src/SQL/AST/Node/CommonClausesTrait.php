<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


trait CommonClausesTrait {

    /** @var OrderExpression[] */
    public $orderBy = [];

    /** @var Literal|null */
    public $limit = null;

    /** @var Literal|null */
    public $offset = null;

}
