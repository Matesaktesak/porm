<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


trait CommonClausesTrait {

    /** @var OrderExpression[] */
    public array $orderBy = [];

    /** @var Expression|null */
    public ?Expression $limit = null;

    /** @var Expression|null */
    public ?Expression $offset = null;

}
