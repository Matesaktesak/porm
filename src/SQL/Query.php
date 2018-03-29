<?php

declare(strict_types=1);

namespace PORM\SQL;


use PORM\Exceptions\InvalidQueryException;

class Query {

    private $sql;

    private $parameterIndex = [];

    private $parameterMap;

    private $resultMap;



    public function __construct(string $sql, array $parameterMap = [], array $resultMap = []) {
        $this->sql = $sql;
        $this->parameterMap = $parameterMap;
        $this->resultMap = $resultMap;
        $this->buildParameterIndex();
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

        $this->parameterMap[$this->parameterIndex[$param]]['value'] = $value;
    }

    public function hasParameters() : bool {
        return !empty($this->parameters);
    }

    public function getParameters() : array {
        foreach ($this->parameterIndex as $key => $index) {
            if (!isset($this->parameterMap[$index]['value']) && !key_exists('value', $this->parameterMap[$index])) {
                throw new InvalidQueryException("Missing required parameter '$key'");
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


    private function buildParameterIndex() : void {
        foreach ($this->parameterMap as $i => $info) {
            if ($info['key'] !== null) {
                $this->parameterIndex[$info['key']] = $i;
            }
        }
    }

}
