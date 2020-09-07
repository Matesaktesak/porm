<?php

declare(strict_types=1);

namespace PORM\Bridges\Tracy;


class Helpers {

    final private function __construct() {}

    public static function formatSql(string $query) : string {
        return preg_replace_callback(
            '/(?<=[\h(+-])((?<!^)SELECT|(EXTRACT\h*\(\h*\S+\h+)?FROM|(?:(?:LEFT|RIGHT|INNER|OUTER)\h+)*JOIN|WHERE|SET|GROUP\h+BY|ORDER\h+BY|INTO|VALUES|UNION)(?=\h)/i',
            function(array $m) : string { return !isset($m[2]) ? "\n" . $m[1] : $m[1]; },
            $query
        );
    }

    public static function formatTime(?float $duration = null) : string {
        if ($duration !== null) {
            return sprintf('%.3f ms', $duration * 1000);
        } else {
            return '';
        }
    }

}
