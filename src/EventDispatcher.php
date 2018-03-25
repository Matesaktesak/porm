<?php

declare(strict_types=1);

namespace PORM;



class EventDispatcher {

    /** @var callable */
    private $listenerResolver;

    /** @var callable[][] */
    private $listeners = [];


    public function setListenerResolver(callable $registry) : void {
        $this->listenerResolver = $registry;
    }


    public function addListener(string $event, $listener, ?string $method = null) : void {
        if (!$method) {
            $p = mb_strrpos($event, '::');
            $method = 'handle' . ucfirst($p !== false ? mb_substr($event, $p + 2) : $event);
        }

        $this->listeners[$event][] = [$listener, $method];
    }


    public function dispatch(string $event, ... $args) : void {
        $this->doDispatch($event, $args);

        if (strpos($event, '::') !== false) {
            $event = preg_replace('/^.*(::.+)$/', '*$1', $event);
            $this->doDispatch($event, $args);
        }
    }


    private function doDispatch(string $event, array $args) : void {
        if (!empty($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $id => $listener) {
                if (!is_object($listener[0]) && isset($this->listenerResolver)) {
                    $this->listeners[$event][$id][0] = $listener[0] = call_user_func($this->listenerResolver, $listener[0]);
                }

                call_user_func_array($listener, $args);
            }
        }
    }

}
