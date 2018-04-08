<?php

declare(strict_types=1);

namespace PORM\SQL\AST;


interface IVisitor {

    public const ENTER = 0b01,
                 LEAVE = 0b10,
                 BOTH  = 0b11;

    public function getNodeTypes() : array;

}
