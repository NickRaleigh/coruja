<?php

namespace Coruja\Cms;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Bridges a Page and its blueprint to/from an HTML form.
 *
 * - {@see values()} produces the current value for each blueprint field
 * - {@see apply()} folds a submitted `f[...]` payload back into the page document,
 *   leaving every non-blueprinted key (template, status, sort, ...) untouched
 *
 * @phpstan-type FieldErrors array<string, string>
 */
final class PageForm
{
    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $blueprint
     *
     * @return array<string, mixed> path => current value
     */
    public function values(array $raw, array $blueprint): array
    {
        $out = [];
        foreach ($blueprint['fields'] as $path => $def) {
            $out[$path] = $this->readValue($raw, $path, $def);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $raw       existing page document
     * @param array<string, mixed> $blueprint
     * @param array<string, mixed> $submitted the `f` array from the request
     * @param FieldErrors          $errors    populated with path => message on failure
     *
     * @return array<string, mixed> the updated document
     */
    public function apply(array $raw, array $blueprint, array $submitted, array &$errors = []): array
    {
        foreach ($blueprint['fields'] as $path => $def) {
            $raw = $this->applyField($raw, $path, $def, $submitted[$path] ?? null, $errors);
        }

        return $raw;
    }

    private function readValue(array $raw, string $path, array $def): mixed
    {
        $value = Dot::get($raw, $path);

        return match ($def['type']) {
            'yaml' => null === $value ? '' : rtrim(Yaml::dump($value, 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)),
            'list', 'structure', 'blocks' => \is_array($value) ? array_values($value) : [],
            'group' => \is_array($value) ? $value : [],
            'select' => (string) ($value ?? $def['default'] ?? ''),
            default => null === $value ? '' : (string) $value,
        };
    }

    private function applyField(array $raw, string $path, array $def, mixed $submitted, array &$errors): array
    {
        switch ($def['type']) {
            case 'yaml':
                $text = trim((string) $submitted);
                if ('' === $text) {
                    Dot::remove($raw, $path);
                    break;
                }
                try {
                    Dot::set($raw, $path, Yaml::parse($text));
                } catch (ParseException $e) {
                    $errors[$path] = 'YAML error: '.$e->getMessage();
                }
                break;

            case 'list':
                $this->setOrRemove($raw, $path, $this->cleanList((array) $submitted));
                break;

            case 'structure':
                $this->setOrRemove($raw, $path, $this->cleanStructure((array) $submitted, $def['fields'] ?? []));
                break;

            case 'group':
                $this->setOrRemove($raw, $path, $this->cleanGroup((array) $submitted, $def['fields'] ?? []));
                break;

            case 'blocks':
                $this->setOrRemove($raw, $path, $this->cleanSections((array) $submitted));
                break;

            case 'textarea':
            case 'richtext':
                $text = rtrim(str_replace("\r\n", "\n", (string) $submitted));
                if ('' === $text && null === Dot::get($raw, $path)) {
                    break; // optional field left blank — keep it out of the file
                }
                Dot::set($raw, $path, $text);
                break;

            default: // text, select, slug, image
                $val = trim((string) $submitted);
                $atDefault = \array_key_exists('default', $def) && $val === $def['default'];
                if (('' === $val || $atDefault) && null === Dot::get($raw, $path)) {
                    break; // blank / left at the default and not already saved — don't create the key
                }
                Dot::set($raw, $path, $val);
        }

        return $raw;
    }

    /**
     * @param array<mixed> $in
     *
     * @return list<string>
     */
    private function cleanList(array $in): array
    {
        $out = [];
        foreach ($in as $v) {
            $v = trim((string) $v);
            if ('' !== $v) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed>         $rows
     * @param array<string, mixed> $fields
     *
     * @return list<array<string, mixed>>
     */
    private function cleanStructure(array $rows, array $fields): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $clean = [];
            $substantive = []; // everything except selects, which always carry a value
            foreach ($fields as $sub => $subDef) {
                $subDef = \is_array($subDef) ? $subDef : [];
                $type = $subDef['type'] ?? 'text';
                $clean[$sub] = match ($type) {
                    'structure' => $this->cleanStructure((array) ($row[$sub] ?? []), $subDef['fields'] ?? []),
                    'list' => $this->cleanList((array) ($row[$sub] ?? [])),
                    default => trim((string) ($row[$sub] ?? '')),
                };
                if ('select' !== $type) {
                    $substantive[$sub] = $clean[$sub];
                }
            }
            if ($this->rowHasContent($substantive)) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * A fixed set of sub-fields, one of which may be a `structure` or `list`.
     * Returns `[]` (so the key is dropped) when every sub-value is empty.
     *
     * @param array<mixed>         $submitted
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function cleanGroup(array $submitted, array $fields): array
    {
        $group = [];
        foreach ($fields as $sub => $subDef) {
            $subDef = \is_array($subDef) ? $subDef : [];
            $in = $submitted[$sub] ?? null;
            $group[$sub] = match ($subDef['type'] ?? 'text') {
                'structure' => $this->cleanStructure((array) $in, $subDef['fields'] ?? []),
                'list' => $this->cleanList((array) $in),
                'textarea', 'richtext' => rtrim(str_replace("\r\n", "\n", (string) $in)),
                default => trim((string) $in),
            };
        }

        return $this->rowHasContent($group) ? $group : [];
    }

    /**
     * @param array<mixed> $submitted
     *
     * @return list<array{label: string, body: list<array<string, mixed>>}>
     */
    private function cleanSections(array $submitted): array
    {
        $sections = [];
        foreach ($submitted as $sec) {
            if (!\is_array($sec)) {
                continue;
            }
            $label = trim((string) ($sec['label'] ?? ''));
            $body = [];
            foreach ((array) ($sec['body'] ?? []) as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $type = \in_array($block['type'] ?? '', ['p', 'h3', 'note', 'list'], true) ? $block['type'] : 'p';

                if ('list' === $type) {
                    $items = [];
                    foreach ((array) ($block['items'] ?? []) as $item) {
                        $item = trim((string) $item);
                        if ('' !== $item) {
                            $items[] = $item;
                        }
                    }
                    if ($items) {
                        $body[] = ['type' => 'list', 'items' => $items];
                    }
                } else {
                    $text = rtrim(str_replace("\r\n", "\n", (string) ($block['text'] ?? '')));
                    if ('' !== $text) {
                        $body[] = ['type' => $type, 'text' => $text];
                    }
                }
            }
            if ('' !== $label || $body) {
                $sections[] = ['label' => $label, 'body' => $body];
            }
        }

        return $sections;
    }

    /** Write a collection value, or drop the key entirely when it is empty. */
    private function setOrRemove(array &$raw, string $path, array $value): void
    {
        if ([] === $value) {
            Dot::remove($raw, $path);
        } else {
            Dot::set($raw, $path, $value);
        }
    }

    private function rowHasContent(array $row): bool
    {
        foreach ($row as $v) {
            if (\is_array($v) ? [] !== $v : '' !== (string) $v) {
                return true;
            }
        }

        return false;
    }
}
