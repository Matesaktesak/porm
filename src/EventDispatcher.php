<?php

declare(strict_types=1);

namespace PORM;



class EventDispatcher {

    /** @var callable */
    private $listenerResolver;

    /** @var callable[][] */
    private array $listeners = [];


    public function setListenerResolver(callable $provider) : void {
        $this->listenerResolver = $provider;
    }


    /**
     * @param string $event
     * @param callable|object|string $listener
     * @param string|null $method
     */
    public function addListener(string $event, callable|object|string $listener, ?string $method = null) : void {
        if (is_string($listener)) {
            if (!isset($this->listenerResolver)) {
                throw new \InvalidArgumentException("Listener references are not supported when listener resolver is not set");
            }
        } else if (!is_object($listener) && !is_callable($listener)) {
            throw new \InvalidArgumentException("Invalid listener, expected an object, string or callable, got " . gettype($listener));
        }

        $this->listeners[$event][] = [$listener, $method];
    }


    public function dispatch(string $event, ... $args) : void {
        $this->doDispatch($event, $args);

        if (mb_strpos($event, '::') !== false) {
            $event = preg_replace('/^.*(::.+)$/', '*$1', $event);
            $this->doDispatch($event, $args);
        }
    }


    private function doDispatch(string $event, array $args) : void {
        if (!empty($this->listeners[$event])) {
            $defaultMethod = 'handle' . ucfirst(preg_replace('/^.+::/', '', $event));

            foreach ($this->listeners[$event] as $id => [$listener, $method]) {
                if (!is_object($listener) || $listener instanceof \Closure && $method) {
                    if (is_string($listener)) {
                        $this->listeners[$event][$id][0] = $listener = call_user_func($this->listenerResolver, $listener, $event);
                    } else {
                        $this->listeners[$event][$id][0] = $listener = call_user_func($listener, $event);
                    }

                    if ($listener instanceof \Closure && $method) {
                        throw new \RuntimeException('Listener factory may not return a Closure when a method is specified');
                    }
                }

                if (!($listener instanceof \Closure)) {
                    $listener = [$listener, $method ?? $defaultMethod];
                }

                call_user_func_array($listener, $args);
            }
        }
    }

}
