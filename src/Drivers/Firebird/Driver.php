<?php

declare(strict_types=1);

namespace PORM\Drivers\Firebird;

use PORM\Exceptions\ConnectionException;
use PORM\Exceptions\ConstraintViolationException;
use PORM\Exceptions\DriverException;
use PORM\Drivers\IDriver;
use PORM\Exceptions\ForeignKeyConstraintViolationException;
use PORM\Exceptions\QueryException;
use PORM\Exceptions\UniqueConstraintViolationException;
use PORM\SQL\ResultSet;


class Driver implements IDriver {

    public const DEFAULTS = [
        'database' => null,
        'username' => null,
        'password' => null,
        'charset' => 'utf8',
        'buffers' => 0,
        'lazy' => true,
    ];


    /** @var array */
    private array $options;

    /** @var resource */
    private $connection;

    /** @var resource */
    private $transaction;

    /** @var int */
    private int $transactionDepth = 0;


    public function __construct(array $options) {
        $this->options = $options + static::DEFAULTS;

        if (empty($this->options['lazy'])) {
            $this->connect();
        }
    }

    public function isConnected() : bool {
        return is_resource($this->connection);
    }

    public function connect() : void {
        if (!$this->connection) {
            $connection = @ibase_connect(
                $this->options['database'],
                $this->options['username'],
                $this->options['password'],
                $this->options['charset'],
                $this->options['buffers']
            );

            if (!is_resource($connection)) {
                throw new ConnectionException(ibase_errmsg(), ibase_errcode());
            } else {
                $this->connection = $connection;
            }
        }
    }

    public function disconnect() : void {
        if ($this->connection) {
            ibase_close($this->connection);
            $this->connection = null;
        }
    }

    public function getLastGeneratedValue(string $name) : int {
        return (int) ibase_gen_id($name, 0, $this->connection);
    }


    public function query(string $query, ?array $parameters = null) : ?ResultSet {
        $this->connect();

        if (empty($parameters)) {
            $result = @ibase_query($this->connection, $query);
        } else {
            $stmt = @ibase_prepare($query);

            if ($stmt === false) {
                throw $this->createQueryException($query, $parameters);
            }

            $result = @ibase_execute($stmt, ... $parameters);
            ibase_free_query($stmt);
        }

        if ($result === false) {
            throw $this->createQueryException($query, $parameters);
        } else {
            return is_resource($result) ? new ResultSet($this, $result) : null;
        }
    }

    public function getAffectedRows() : int {
        return ibase_affected_rows($this->connection);
    }

    public function fetchRow($resource) : ?array {
        $result = @ibase_fetch_assoc($resource, IBASE_TEXT);

        if ($code = ibase_errcode()) {
            throw new DriverException(ibase_errmsg(), $code);
        } else {
            return $result === false ? null : $result;
        }
    }

    public function freeResult($resource) : void {
        ibase_free_result($resource);
    }


    public function inTransaction() : bool {
        return $this->transactionDepth > 0;
    }

    public function beginTransaction() : void {
        $this->connect();

        if ($this->inTransaction()) {
            $this->query(sprintf('SAVEPOINT PORM_%d', $this->transactionDepth));
        } else {
            $this->transaction = ibase_trans($this->connection);
        }

        $this->transactionDepth++;
    }

    public function commit() : void {
        if (!$this->inTransaction()) {
            throw new DriverException("No active transaction to commit");
        } else if (--$this->transactionDepth === 0) {
            if (!ibase_commit($this->transaction)) {
                throw new DriverException("Unable to commit transaction");
            }

            $this->transaction = null;
        }
    }

    public function rollback() : void {
        if (!$this->inTransaction()) {
            throw new DriverException("No active transaction to roll back");
        } else if ($this->transactionDepth > 1) {
            $this->query(sprintf('ROLLBACK TO SAVEPOINT PORM_%d', --$this->transactionDepth));
        } else {
            if (!ibase_rollback($this->transaction)) {
                throw new DriverException("Unable to roll back transaction");
            }

            $this->transaction = null;
            $this->transactionDepth = 0;
        }
    }



    private function createQueryException(string $query, ?array $parameters = null) : QueryException {
        $msg = ibase_errmsg();
        $code = ibase_errcode() ?: -1;

        if (!$msg) {
            if ($err = error_get_last()) {
                error_clear_last();
                $msg = $err['message'];
            } else {
                $msg = 'Unknown Firebird error';
            }
        }

        if (preg_match('~violation of (.+?) constraint~i', $msg, $m)) {
            $class = lcfirst($m[1]) === 'f' ? ForeignKeyConstraintViolationException::class : UniqueConstraintViolationException::class;
        } else if (preg_match('~validation error for column~i', $msg)) {
            $class = ConstraintViolationException::class;
        } else {
            $class = QueryException::class;
        }

        return new $class($msg, $code, $query, $parameters);
    }

}
