<?php

declare(strict_types=1);

namespace PORM\SQL\AST;


interface ILeaveVisitor extends IVisitor {

    public function leave(Node\Node $node, Context $context) : void;

}
