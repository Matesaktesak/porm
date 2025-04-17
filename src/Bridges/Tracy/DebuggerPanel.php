<?php

namespace PORM\Bridges\Tracy;

use PORM\Exceptions\QueryException;
use Tracy;


class DebuggerPanel {

    public function __invoke($e) : ?array {
        if ($e instanceof QueryException && $e->hasQuery()) {
            if ($e->hasParameters()) {
                $p = '<h3>Parameters:</h3>' . Tracy\Dumper::toHtml($e->getParameters());
            } else {
                $p = '';
            }

            return [
                'tab' => 'SQL',
                'panel' => '<pre class="code"><div>' . htmlspecialchars(Helpers::formatSql($e->getQuery())) . '</div></pre>' . $p,
            ];
        } else {
            return null;
        }
    }

}
