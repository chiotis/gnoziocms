<?php

declare(strict_types=1);

namespace GnozioCMS;

/** Reading and writing a value deep inside nested arrays by its path: ['a', 'b'] is `$data['a']['b']`. */
final class ArrayPath
{
    public static function get(array $source, array $path): mixed
    {
        $node = $source;
        foreach ($path as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    public static function set(array &$target, array $path, mixed $value): void
    {
        if (empty($path)) {
            return;
        }
        $node = &$target;
        foreach ($path as $index => $segment) {
            $segment = (string)$segment;
            if ($segment === '') {
                return;
            }
            $isLeaf = $index === count($path) - 1;
            if ($isLeaf) {
                $node[$segment] = $value;
                return;
            }
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
    }

    public static function unset(array &$target, array $path): void
    {
        if (empty($path)) {
            return;
        }
        $node = &$target;
        $last = count($path) - 1;
        foreach ($path as $index => $segment) {
            $segment = (string)$segment;
            if ($segment === '') {
                return;
            }
            if ($index === $last) {
                unset($node[$segment]);
                return;
            }
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                return;
            }
            $node = &$node[$segment];
        }
    }
}
