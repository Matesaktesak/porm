<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Node;


class ArithmeticExpression extends Expression {
    use BinaryExpressionTrait;
}
