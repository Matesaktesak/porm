<?php

declare(strict_types=1);

namespace PORM\Cache;

use Nette\PhpGenerator;


class Helpers {

    public static function serializeInstance($obj, array $props) : string {
        static $dumper = null;

        if (!isset($dumper)) {
            if (class_exists(PhpGenerator\Helpers::class)) {
                $dumper = \Closure::fromCallable([PhpGenerator\Helpers::class, 'dump']);
            } else {
                $dumper = function($value) { return var_export($value, true); };
            }
        }

        $class = get_class($obj);
        $src = "<?php\n\ndeclare(strict_types=1);\n\n";
        $src .= 'return new ' . $class . "(\n";
        $obj = (array) $obj;
        $params = [];

        foreach ($props as $prop => $comment) {
            $params[] = '// ' . $comment . "\n" . $dumper->__invoke($obj["\0$class\0$prop"]);
        }

        $src .= preg_replace('/^/m', '    ', implode(",\n", $params));
        $src .= "\n);\n";

        return $src;
    }

}
