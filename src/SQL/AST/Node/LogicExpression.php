<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class LogicExpression extends Expression {
    use BinaryExpressionTrait;
}
