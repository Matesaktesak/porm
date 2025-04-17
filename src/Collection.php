<?php

declare(strict_types=1);

namespace PORM;


class Collection implements \ArrayAccess, \Countable, \IteratorAggregate {

    private array $entries;

    private array $added = [];

    private array $removed = [];


    public function __construct(array $entries = []) {
        $this->entries = $entries;
    }


    public function add(object $entry) : void {
        if (!$this->has($entry)) {
            $this->entries[] = $this->added[] = $entry;
        }
    }

    public function merge(array $entries, bool $new = false) : void {
        $entries = array_udiff($entries, $this->entries, function(object $a, object $b) : int {
            return $a === $b ? 0 : -1;
        });

        if ($entries) {
            array_push($this->entries, ... $entries);

            if ($new) {
                array_push($this->added, ... $entries);
            }
        }
    }

    public function remove(object $entry) : void {
        if (($key = array_search($entry, $this->entries, true)) !== false) {
            array_splice($this->entries, $key, 1);
        }

        $this->removed[] = $entry;
    }

    public function get(int $key) : object {
        return $this->entries[$key];
    }

    public function has(object $entry) : bool {
        return in_array($entry, $this->entries, true);
    }

    public function toArray() : array {
        return $this->entries;
    }



    public function getAddedEntries() : array {
        $entries = $this->added;
        $this->added = [];
        return $entries;
    }

    public function getRemovedEntries() : array {
        $entries = $this->removed;
        $this->removed = [];
        return $entries;
    }



    public function getIterator() : \Generator {
        yield from $this->entries;
    }


    public function count() : int {
        return count($this->entries);
    }


    public function offsetExists($offset) : bool {
        return isset($this->entries[$offset]);
    }

    public function offsetGet($offset) : object {
        return $this->get($offset);
    }

    public function offsetSet($offset, $value) : void {
        if ($offset === null) {
            $this->add($value);
        } else {
            throw new \InvalidArgumentException("Unable to set specific collection keys");
        }
    }

    public function offsetUnset($offset) : void {
        if (isset($this->entries[$offset])) {
            $this->remove($this->entries[$offset]);
        }
    }


}
