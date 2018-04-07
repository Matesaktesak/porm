<?php

declare(strict_types=1);

namespace PORM\SQL;

use PORM\Drivers\IPlatform;
use PORM\Cache;
use PORM\EventDispatcher;
use PORM\Exceptions\InvalidQueryException;
use PORM\Metadata\Provider;
use PORM\SQL\AST\IVisitor;


class Translator {

    private $parser;

    private $walker;

    private $metadataProvider;

    private $platform;

    private $eventDispatcher;

    private $cache;

    /** @var IVisitor[] */
    private $visitors = null;


    public function __construct(
        AST\Parser $parser,
        Provider $metadataProvider,
        IPlatform $platform,
        EventDispatcher $eventDispatcher,
        ?Cache\IStorage $cache = null
    ) {
        $this->parser = $parser;
        $this->walker = new AST\Walker();
        $this->metadataProvider = $metadataProvider;
        $this->platform = $platform;
        $this->eventDispatcher = $eventDispatcher;
        $this->cache = $cache;
    }


    public function translate(string $query) : Query {
        if ($this->cache) {
            return $this->cache->get(
                $this->getQueryCacheKey($query),
                function() use ($query) {
                    return $this->doTranslate($query);
                }
            );
        } else {
            return $this->doTranslate($query);
        }
    }

    public function compile(AST\Node\Query $ast) : Query {
        $this->eventDispatcher->dispatch(self::class . '::beforeCompile', $ast);

        foreach ($this->getASTVisitors() as $visitors) {
            $this->walker->apply($ast, ... $visitors);
        }

        $this->eventDispatcher->dispatch(self::class . '::compile', $ast);

        switch (get_class($ast)) {
            case AST\Node\SelectQuery::class: /** @var AST\Node\SelectQuery $ast */
                return $this->platform->formatSelectQuery($ast);
            case AST\Node\InsertQuery::class: /** @var AST\Node\InsertQuery $ast */
                return $this->platform->formatInsertQuery($ast);
            case AST\Node\UpdateQuery::class: /** @var AST\Node\UpdateQuery $ast */
                return $this->platform->formatUpdateQuery($ast);
            case AST\Node\DeleteQuery::class: /** @var AST\Node\DeleteQuery $ast */
                return $this->platform->formatDeleteQuery($ast);
            default:
                throw new InvalidQueryException(":-(");
        }
    }


    private function doTranslate(string $query) : Query {
        $ast = $this->parser->parseQuery($query);
        return $this->compile($ast);
    }

    private function getQueryCacheKey(string $query) : string {
        return sha1($query) . strlen($query);
    }

    private function getASTVisitors() : array {
        if ($this->visitors === null) {
            $this->visitors[] = [
                new AST\Visitor\EntityResolverVisitor($this->metadataProvider),
                new AST\Visitor\SubqueryMappingVisitor(),
                new AST\Visitor\JoinConditionResolverVisitor($this->metadataProvider),
                new AST\Visitor\IdentifierResolverVisitor(),
                new AST\Visitor\ResultMappingVisitor(),
            ];

            $this->visitors[] = [
                new AST\Visitor\ParameterResolverVisitor(),
            ];
        }

        return $this->visitors;
    }


    public static function serialize(Query $query) : string {
        return Cache\Helpers::serializeInstance($query, [
            'sql' => 'Compiled SQL',
            'parameterMap' => 'Parameter map',
            'resultMap' => 'Result map',
            'parameterIndex' => 'Parameter index',
        ]);
    }

}
