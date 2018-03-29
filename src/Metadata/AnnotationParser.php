<?php

declare(strict_types=1);

namespace PORM\Metadata;

use PORM\Exceptions\MetadataException;
use PORM\SQL\Expression;


class AnnotationParser {

    public static function parse(?string $comment) : array {
        if (!$comment) {
            return [];
        }

        if (mb_strpos($comment, "\n") === false) {
            $comment = trim(preg_replace('~^/\*+\h*|\h*\*+/$~', '', $comment));
        } else {
            $comment = trim(preg_replace('~^\h*(/\*+\h*|\*+/\h*|\*\h?)~m', '', $comment));
        }

        $cursor = 0;
        $annotations = [];

        while ($cursor < mb_strlen($comment) && ($start = mb_strpos($comment, '@', $cursor)) !== false) {
            $end = mb_strpos($comment, "\n", $start);

            if (($i = mb_strpos($comment, '(', $start)) !== false && ($end === false || $i < $end)) {
                $p = 1;

                while (preg_match('~[()]~', $comment, $m, PREG_OFFSET_CAPTURE, $i + 1)) {
                    $p += $m[0][0] === '(' ? 1 : -1;
                    $i = $m[0][1];

                    if ($p === 0) {
                        break;
                    }
                }

                if ($p !== 0) {
                    $descr = $end - $start > 10 ? mb_substr($comment, $start, 10) . '...' : mb_substr($comment, $start, $end - $start);
                    throw new MetadataException("Mismatched parentheses in annotation '$descr'");
                } else {
                    $end = $i + 1;
                }
            }

            list($name, $value) = self::parseAnnotation(mb_substr($comment, $start, $end !== false ? $end - $start : null));
            $annotations[$name] = $value;

            if ($end === false) {
                break;
            } else {
                $cursor = $end + 1;
            }
        }

        return $annotations;
    }

    private static function parseAnnotation(string $annotation) : array {
        if (preg_match('/^@([^\s(]+)\s*/', $annotation, $m)) {
            $name = $m[1];
            $value = trim(mb_substr($annotation, mb_strlen($m[0]))) ?: null;
        } else {
            $len = min(mb_strpos($annotation, "\n"), 10);
            $descr = $len < mb_strlen($annotation) ? mb_substr($annotation, 0, $len) . '...' : $annotation;
            throw new MetadataException("Invalid annotation '$descr'");
        }

        if ($value && $value[0] === '(') {
            $value = self::parseValue(mb_substr($value, 1, -1));
        }

        return [$name, $value];
    }

    private static function parseValue(string $value) : array {
        $tokens = preg_split('~("(?:\\\\.|[^"\\\\\n])*+"|[={}(),@])|\s++~i', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $value = [];
        $cursor = & $value;
        $stack = [];
        $key = null;

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];

            switch ($t) {
                case '=':
                    if (!$key) {
                        throw new MetadataException("Invalid annotation");
                    }
                    break;
                case ',':
                    break;
                case '{':
                    $stack[] = & $cursor;

                    if ($key !== null) {
                        $cursor = & $cursor[$key];
                        $key = null;
                    } else {
                        $cursor = & $cursor[];
                    }
                    break;
                case '}':
                    unset($cursor);
                    $cursor = & $stack[count($stack) - 1];
                    array_pop($stack);
                    break;
                case '@':
                    $j = $i + 1;

                    if ($tokens[$j + 1] === '(') {
                        for ($j += 2, $p = 1; $j < $n && $p > 0; $j++) {
                            if ($tokens[$j] === '(') $p++;
                            else if ($tokens[$j] === ')') $p--;
                        }
                    }

                    list($annot, $val) = self::parseAnnotation(implode('', array_slice($tokens, $i, $j - $i)));
                    $i = $j - 1;

                    if ($annot === 'Expr') {
                        $val = new Expression($val[0], $val[1] ?? null);
                    } else {
                        throw new MetadataException("Unknown annotation @$annot");
                    }

                    if ($key !== null) {
                        $cursor[$key] = $val;
                        $key = null;
                    } else {
                        $cursor[] = $val;
                    }
                    break;
                default:
                    if ($i + 1 < $n && $tokens[$i + 1] === '=') {
                        $key = $t[0] === '"' ? mb_substr($t, 1, -1) : $t;
                    } else {
                        $v = @json_decode($t, true);

                        if (json_last_error()) {
                            $v = $t[0] === '"' ? mb_substr($t, 1, -1) : $t;
                        }

                        if ($key) {
                            $cursor[$key] = $v;
                            $key = null;
                        } else {
                            $cursor[] = $v;
                        }
                    }
                    break;
            }
        }

        return $value;
    }

}
