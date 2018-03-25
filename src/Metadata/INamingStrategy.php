<?php

declare(strict_types=1);

namespace PORM\Metadata;


interface INamingStrategy {

    public function setTableContext(string $tableName, array $annotations) : void;

    public function formatTableName(string $className, array $annotations) : string;

    public function formatColumnName(string $propertyName, array $annotations) : string;

    public function formatGeneratorName(string $propertyName, string $columnName, array $annotations) : string;

}
