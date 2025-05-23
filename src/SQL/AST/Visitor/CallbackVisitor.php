<?php

declare(strict_types=1);

namespace PORM\SQL\AST\Visitor;

use PORM\SQL\AST\Context;
use PORM\SQL\AST\IEnterVisitor;
use PORM\SQL\AST\ILeaveVisitor;
use PORM\SQL\AST\Node;


class CallbackVisitor implements IEnterVisitor, ILeaveVisitor {

    private $enter;

    private $leave;

    private array $nodeTypes;


    public static function forEnter(callable $handler, string ... $nodeTypes) : self {
        return new static($handler, null, ... $nodeTypes);
    }

    public static function forLeave(callable $handler, string ... $nodeTypes) : self {
        return new static(null, $handler, ... $nodeTypes);
    }


    public function __construct(?callable $enter, ?callable $leave, string ... $nodeTypes) {
        $this->enter = $enter;
        $this->leave = $leave;
        $this->nodeTypes = $nodeTypes;
    }


    public function getNodeTypes() : array {
        return $this->nodeTypes;
    }

    public function enter(Node\Node $node, Context $context) : void {
        if (isset($this->enter)) {
            call_user_func($this->enter, $node, $context);
        }
    }

    public function leave(Node\Node $node, Context $context) : void {
        if (isset($this->leave)) {
            call_user_func($this->leave, $node, $context);
        }
    }

}
