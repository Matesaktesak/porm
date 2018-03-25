<?php

declare(strict_types=1);

namespace PORM\SQL\AST;


interface IVisitor {

    public function getNodeTypes() : array;

    public function init() : void;

    public function enter(Node\Node $node, Context $context) : void;

    public function leave(Node\Node $node, Context $context) : void;

}
