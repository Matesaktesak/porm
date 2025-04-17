<?php

declare(strict_types=1);

namespace PORM;


class Connection {


    /** @var callable[] */
    private array $listeners = [];


    private Drivers\IDriver $driver;

    private Drivers\IPlatform $platform;


    public function __construct(Drivers\IDriver $driver, Drivers\IPlatform $platform) {
        $this->driver = $driver;
        $this->platform = $platform;
    }


    public function addListener(callable $listner) : void {
        $this->listeners[] = $listner;
    }


    public function getDriver() : Drivers\IDriver {
        return $this->driver;
    }

    public function getPlatform() : Drivers\IPlatform {
        return $this->platform;
    }

    public function connect() : void {
        $this->driver->connect();
    }

    public function disconnect() : void {
        $this->driver->disconnect();
    }


    public function query(string $query, ?array $parameters = null) : ?SQL\ResultSet {
        $this->connect();

        $event = !empty($this->listeners) ? new SQL\Event($query, $parameters) : null;

        if ($event) {
            $event->start();
        }

        $result = $this->driver->query($query, $parameters);

        if ($event) {
            $event->end();

            if ($result) {
                $event->setResultSet($result);
            }

            if (preg_match('/(^|\s)(insert|delete|update|merge|replace)\s/i', $query)) {
                $event->setAffectedRows($this->driver->getAffectedRows());
            }

            $this->emit($event);
        }

        return $result;
    }

    public function getAffectedRows() : int {
        return $this->driver->getAffectedRows();
    }


    public function inTransaction() : bool {
        return $this->driver->inTransaction();
    }

    public function beginTransaction() : void {
        $this->connect();
        $this->driver->beginTransaction();
    }

    public function commit() : void {
        $this->driver->commit();
    }

    public function rollback() : void {
        $this->driver->rollback();
    }


    private function emit(SQL\Event $event) : void {
        foreach ($this->listeners as $listener) {
            call_user_func($listener, $event);
        }
    }


    public function __destruct() {
        $this->disconnect();
    }
}
