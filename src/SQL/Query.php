<?php

declare(strict_types=1);

namespace PORM\SQL;


use PORM\Exceptions\InvalidQueryException;

class Query {

    private $sql;

    private $parameterIndex = [];

    private $parameterMap;

    private $resultMap;



    public function __construct(string $sql, array $parameterMap = [], array $resultMap = [], ?array $parameterIndex = null) {
        $this->sql = $sql;
        $this->parameterMap = $parameterMap;
        $this->resultMap = $resultMap;

        if ($parameterIndex !== null) {
            $this->parameterIndex = $parameterIndex;
        } else {
            $this->parameterIndex = $this->buildParameterIndex($this->parameterMap);
        }
    }

    public function getSql() : string {
        return $this->sql;
    }

    public function setParameters(array $parameters) : void {
        foreach ($parameters as $param => $value) {
            $this->setParameter($param, $value);
        }
    }

    public function setParameter($param, $value) : void {
        if (!isset($this->parameterIndex[$param])) {
            throw new \InvalidArgumentException("Invalid parameter '$param'");
        }

        foreach ($this->parameterIndex[$param] as $id) {
            $this->parameterMap[$id]['value'] = $value;
        }
    }

    public function hasParameters() : bool {
        return !empty($this->parameters);
    }

    public function getParameters() : array {
        foreach ($this->parameterIndex as $key => $ids) {
            foreach ($ids as $id) {
                if (!isset($this->parameterMap[$id]['value']) && !key_exists('value', $this->parameterMap[$id])) {
                    throw new InvalidQueryException("Missing required parameter '$key'");
                }
            }
        }

        return array_column($this->parameterMap, 'value');
    }

    public function getParameterMap() : array {
        return $this->parameterMap;
    }

    public function getResultMap() : array {
        return $this->resultMap;
    }


    private function buildParameterIndex(array $parameterMap) : array {
        $index = [];

        foreach ($parameterMap as $id => $info) {
            if ($info['key'] !== null) {
                $index[$info['key']][] = $id;
            }
        }

        return $index;
    }

}
