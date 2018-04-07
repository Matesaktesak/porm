<?php

declare(strict_types=1);

namespace PORM\Metadata;


interface ICompilerPass {

    public function process(\ReflectionClass $entity, array & $meta, Compiler $compiler) : void;

}
