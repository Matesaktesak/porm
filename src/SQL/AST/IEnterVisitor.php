<?php

declare(strict_types=1);

namespace PORM\SQL\AST;


interface IEnterVisitor extends IVisitor {

    public function enter(Node\Node $node, Context $context) : void;

}
