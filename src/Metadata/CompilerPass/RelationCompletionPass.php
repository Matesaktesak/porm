<?php

declare(strict_types=1);

namespace PORM\Metadata\CompilerPass;

use PORM\Exceptions\MetadataException;
use PORM\Metadata\Compiler;
use PORM\Metadata\ICompilerPass;


class RelationCompletionPass implements ICompilerPass {

    public function process(\ReflectionClass $entity, array & $meta, Compiler $compiler) : void {
        foreach ($meta['relations'] as $prop => & $info) {
            if (!isset($info['property'])) {
                $this->completeInverseRelationInfo($entity, $meta, $prop, $info, $compiler);
            }
        }
    }


    private function completeInverseRelationInfo(\ReflectionClass $entity, array & $meta, string $prop, array & $info, Compiler $compiler) : void {
        $target = $compiler->getMeta($info['target']);

        if (isset($target['relationMap'][$entity->getName()][$prop])) {
            $tprop = $target['relationMap'][$entity->getName()][$prop];

            $src = [
                $tprop => $target['relations'][$tprop],
            ];
        } else {
            $src = $target['relations'];
        }

        /** @var string $id */
        /** @var string $tid */
        $id = count($meta['identifierProperties']) === 1 ? reset($meta['identifierProperties']) : null;
        $tid = count($target['identifierProperties']) === 1 ? reset($target['identifierProperties']) : null;
        $candidates = [];

        foreach ($src as $tprop => $tinfo) {
            if ($tinfo['target'] !== $entity->getName() || !empty($tinfo['property']) && $tinfo['property'] !== $prop) {
                continue;
            }

            if (empty($info['fk']) !== empty($tinfo['fk'])) {
                $candidates[] = [$tprop, null];
            } else if ($id && $tid && !empty($info['collection']) && !empty($tinfo['collection'])) {
                $via = $this->normalizeVia($info['via'] ?? null, $id, $tid);
                $tvia = $this->normalizeVia($tinfo['via'] ?? null, $tid, $id);

                if ($via['table'] && $tvia['table'] && $via['table'] !== $tvia['table']) {
                    continue;
                }

                $tentity = $compiler->getReflection($info['target']);
                $strategy = $compiler->getNamingStrategy();

                if (!$via['table']) {
                    if ($tvia['table']) {
                        $via['table'] = $tvia['table'];
                    } else {
                        $entities = [
                            $entity->getName() => $entity,
                            $info['target'] => $tentity,
                        ];

                        ksort($entities);

                        $via['table'] = $strategy->formatAssignmentTableName(
                            reset($entities),
                            end($entities),
                            $compiler
                        );
                    }
                }

                if (!$via['localKey']) {
                    $via['localKey'] = $tvia['remoteKey'] ?? $id;
                }

                if (!$via['remoteKey']) {
                    $via['remoteKey'] = $tvia['localKey'] ?? $tid;
                }

                if (!$via['localColumn'] && $tvia['remoteColumn']) {
                    $via['localColumn'] = $tvia['remoteColumn'];
                }

                if (!$via['remoteColumn'] && $tvia['localColumn']) {
                    $via['remoteColumn'] = $tvia['localColumn'];
                }

                $candidates[] = [
                    $tprop,
                    [
                        'table' => $via['table'],
                        'localColumn' => $via['localColumn'] ?? $strategy->formatAssignmentColumnName($entity, $entity->getProperty($id), $compiler),
                        'remoteColumn' => $via['remoteColumn'] ?? $strategy->formatAssignmentColumnName($tentity, $tentity->getProperty($tid), $compiler),
                    ],
                ];
            }
        }

        switch (count($candidates)) {
            case 0:
                break;
            case 1:
                list (
                    $info['property'],
                    $info['via']
                ) = reset($candidates);

                if (!$info['via']) {
                    unset($info['via']);
                }

                $meta['relationMap'][$info['target']][$info['property']] = $prop;
                break;
            default:
                throw new MetadataException("Unable to determine inverse relation info for relation '$prop' of entity '{$entity->getName()}'");
        }
    }


    private function normalizeVia($via, string $localKey, string $remoteKey) : array {
        if (!is_array($via)) {
            $via = [
                'table' => $via,
            ];
        }

        $via += [
            'localKey' => $localKey,
            'remoteKey' => $remoteKey,
            'localColumn' => null,
            'remoteColumn' => null,
        ];

        return $via;
    }

}
