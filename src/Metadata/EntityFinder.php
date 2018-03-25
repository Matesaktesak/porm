<?php

declare(strict_types=1);

namespace PORM\Metadata;

use Composer\Autoload\ClassLoader;
use Nette\Loaders\RobotLoader;


class EntityFinder {

    public function getEntityClassMap(array $namespaces) : array {
        $loader = $this->getClassLoader();
        $map = [];

        foreach ($this->findEntitiesInClassMap($namespaces, $loader->getClassMap()) as $alias => $class) {
            $map[$alias][] = $class;
        }

        if (!$loader->isClassMapAuthoritative() && class_exists(RobotLoader::class)) {
            foreach ($this->findEntitiesInPsr4($namespaces, $loader->getPrefixesPsr4()) as $alias => $class) {
                $map[$alias][] = $class;
            }
        }

        ksort($map);

        return array_map(function(array $classes) : ?string {
            $classes = array_unique($classes);
            return count($classes) === 1 ? reset($classes) : null;
        }, $map);
    }


    private function findEntitiesInClassMap(array $namespaces, array $classMap) : \Generator {
        foreach ($classMap as $class => $file) {
            if (($p = mb_strrpos($class, '\\')) !== false) {
                $ns = mb_substr($class, 0, $p);
                $name = mb_substr($class, $p + 1);
            } else {
                $ns = '';
                $name = $class;
            }

            foreach ($namespaces as $alias => $namespace) {
                if ($namespace === $ns) {
                    if ($this->isEntity($class)) {
                        yield $name => $class;
                        yield $alias . ':' . $name => $class;
                    }

                    break;
                }
            }
        }
    }

    private function findEntitiesInPsr4(array $namespaces, array $psr4) : \Generator {
        foreach ($psr4 as $prefix => $dirs) {
            foreach ($namespaces as $alias => $namespace) {
                if (mb_substr($namespace . '\\', 0, mb_strlen($prefix)) === $prefix) {
                    yield from $this->findEntitiesInDirs($dirs, $namespace, $alias);
                }
            }
        }
    }

    private function findEntitiesInDirs(array $dirs, string $namespace, string $alias) : \Generator {
        $loader = new RobotLoader();
        array_map([$loader, 'addDirectory'], $dirs);
        $loader->rebuild();
        $namespace .= '\\';
        $len = mb_strlen($namespace);

        foreach ($loader->getIndexedClasses() as $class => $file) {
            if (mb_substr($class, 0, $len) === $namespace && mb_strpos($class, '\\', $len) === false && $this->isEntity($class)) {
                $short = mb_substr($class, $len);
                yield $short => $class;
                yield $alias . ':' . $short => $class;
            }
        }
    }


    private function isEntity(string $class) : bool {
        $reflection = new \ReflectionClass($class);

        if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
            return false;
        }

        if (preg_match('~@(Table|Manager)[\s(]~', $reflection->getDocComment() ?: '')) {
            return true;
        }

        foreach ($reflection->getProperties() as $property) {
            if (preg_match('~@(Column|Relation|Aggregate)[\s(]~', $property->getDocComment() ?: '')) {
                return true;
            }
        }

        return false;
    }

    private function getClassLoader() : ClassLoader {
        $reflection = new \ReflectionClass(ClassLoader::class);
        $path = dirname($reflection->getFileName(), 2);
        return require $path . '/autoload.php';
    }

}
