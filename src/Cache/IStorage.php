<?php

declare(strict_types=1);

namespace PORM\Cache;


interface IStorage {

    public function get(string $key, callable $generator);

}
