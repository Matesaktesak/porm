<?php

declare(strict_types=1);

namespace PORM\SQL\AST;


class CallbackVisitor implements IVisitor {

    private $enter;

    private $leave;

    private $nodeTypes;


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

    public function init() : void {

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
