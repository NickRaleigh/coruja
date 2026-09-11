<?php

namespace Coruja\Cms;

/**
 * Dotted-path access into nested associative arrays: "case.gallery.label".
 */
final class Dot
{
    public static function get(array $data, string $path, mixed $default = null): mixed
    {
        $node = $data;
        foreach (explode('.', $path) as $key) {
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }

        return $node;
    }

    public static function set(array &$data, string $path, mixed $value): void
    {
        $keys = explode('.', $path);
        $node = &$data;
        foreach ($keys as $i => $key) {
            if ($i === \count($keys) - 1) {
                $node[$key] = $value;

                return;
            }
            if (!isset($node[$key]) || !\is_array($node[$key])) {
                $node[$key] = [];
            }
            $node = &$node[$key];
        }
    }

    /** Remove a key, then any now-empty ancestor containers. */
    public static function remove(array &$data, string $path): void
    {
        $keys = explode('.', $path);
        $last = array_pop($keys);

        $node = &$data;
        foreach ($keys as $key) {
            if (!\is_array($node[$key] ?? null)) {
                return;
            }
            $node = &$node[$key];
        }
        unset($node[$last]);
    }
}
