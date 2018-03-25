<?php

declare(strict_types=1);

namespace PORM\Metadata\NamingStrategy;

use PORM\Metadata\INamingStrategy;


class SnakeCase implements INamingStrategy {

    private $tableName;


    public function setTableContext(string $tableName, array $annotations) : void {
        $this->tableName = $tableName;
    }

    public function formatTableName(string $className, array $annotations) : string {
        $shortName = ($p = mb_strrpos($className, '\\')) !== false ? mb_substr($className, $p + 1) : $className;
        return $this->toSnakeCase($shortName);
    }

    public function formatColumnName(string $propertyName, array $annotations) : string {
        return $this->toSnakeCase($propertyName);
    }

    public function formatGeneratorName(string $propertyName, string $columnName, array $annotations) : string {
        return $this->tableName . '_' . $propertyName . '_seq';
    }


    protected function toSnakeCase(string $identifier) : string {
        return mb_strtolower(preg_replace('/(?<=[^A-Z])[A-Z]|(?<!^)[A-Z](?=[^A-Z])/', '_$0', $identifier));
    }

}
