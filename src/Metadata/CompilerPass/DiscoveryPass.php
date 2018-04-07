<?php

declare(strict_types=1);

namespace PORM\Metadata\CompilerPass;

use PORM\Metadata\Compiler;
use PORM\Metadata\ICompilerPass;


class DiscoveryPass implements ICompilerPass {

    public function process(\ReflectionClass $entity, array & $meta, Compiler $compiler) : void {
        foreach ($meta['relations'] as $info) {
            if (!$compiler->hasClass($info['target'])) {
                $compiler->registerDiscoveredClass($info['target']);
            }
        }
    }

}
