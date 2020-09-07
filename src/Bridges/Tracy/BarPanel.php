<?php

declare(strict_types=1);

namespace PORM\Bridges\Tracy;

use Composer\Autoload\ClassLoader;
use PORM\SQL\Event;
use Tracy;


class BarPanel implements Tracy\IBarPanel {

    /** @var Event[] */
    private $events = [];


    /** @var string */
    private $pormPath = null;

    /** @var int */
    private $pormPathLen = null;


    public function logEvent(Event $event) : void {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        foreach ($trace as $item) {
            if (isset($item['file']) && !$this->isVendor($item['file'])) {
                $event->file = $item['file'];
                $event->line = $item['line'];
                break;
            }
        }

        $this->events[] = $event;
    }

    public function getTab() : string {
        if (empty($this->events)) {
            return 'ORM';
        } else {
            $total = (float) array_reduce($this->events, function($total, Event $event) {
                return $total + $event->getDuration();
            }, 0);

            return 'ORM (' . Helpers::formatTime($total) . ')';
        }
    }

    public function getPanel() : ?string {
        if (empty($this->events)) {
            return null;
        }

        $src = [
            '<style type="text/css">',
                '.tracy-OrmPanel { max-height: 90vh; overflow-y: auto; }',
                '.tracy-OrmPanel-nowrap { white-space: nowrap; }',
                '.tracy-OrmPanel-sql { max-width: 60vw; overflow: auto; }',
            '</style>',
            '<div class="tracy-OrmPanel">',
                '<table>',
                    '<thead>',
                        '<tr>',
                            '<th>Time</th>',
                            '<th>Query</th>',
                            '<th>Rows</th>',
                        '</tr>',
                    '</thead>',
                    '<tbody>',
        ];

        foreach ($this->events as $event) {
            $src[] = '<tr>';
            $src[] = '<td class="tracy-OrmPanel-nowrap">' . Helpers::formatTime($event->getDuration()) . '</td>';
            $src[] = '<td>';
            $src[] = '<pre class="tracy-OrmPanel-sql">' . htmlspecialchars(Helpers::formatSql($event->getQuery())) . '</pre>';

            if ($event->hasParameters()) {
                $src[] = '<h3>Parameters:</h3>';
                $src[] = Tracy\Dumper::toHtml($event->getParameters(), [
                    Tracy\Dumper::COLLAPSE => true,
                ]);
            }

            if (isset($event->file) && isset($event->line)) {
                $src[] = Tracy\Helpers::editorLink($event->file, $event->line);
            }

            $src[] = '</td>';
            $src[] = '<td class="tracy-OrmPanel-nowrap">';

            if (($fetched = $event->getFetchedRows()) !== null) {
                $src[] = "Fetched: $fetched<br />";
            }

            if (($affected = $event->getAffectedRows()) !== null) {
                $src[] = "Affected: $affected<br />";
            }

            $src[] = '</td>';
            $src[] = '</tr>';
        }

        $src[] = '</tbody>';
        $src[] = '</table>';
        $src[] = '</div>';

        return implode('', $src);
    }

    private function isVendor(string $path) : bool {
        if (class_exists(ClassLoader::class)) {
            return strpos($path, '/vendor/') !== false;
        } else {
            return $this->isPorm($path);
        }
    }

    private function isPorm(string $path) : bool {
        if ($this->pormPath === null) {
            $this->pormPath = dirname(__DIR__, 3) . '/';
            $this->pormPathLen = mb_strlen($this->pormPath);
        }

        return mb_substr($path, 0, $this->pormPathLen) === $this->pormPath;
    }

}
