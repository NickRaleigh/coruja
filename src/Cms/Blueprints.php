<?php

namespace Coruja\Cms;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the panel field definitions from config/blueprints/<template>.yaml.
 *
 * A blueprint:
 *   label: Case study
 *   fields:
 *     case.title:            { label: Title, type: text }
 *     case.tags:             { label: Tags, type: list }
 *     case.meta:             { label: Meta, type: structure, fields: { label: {}, value: {} } }
 *     case.sections:         { label: Sections, type: yaml, help: "..." }
 */
final class Blueprints
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/blueprints')]
        private readonly string $dir,
    ) {
    }

    /** @return array<string, mixed> */
    public function for(string $template): array
    {
        if (isset($this->cache[$template])) {
            return $this->cache[$template];
        }

        $file = $this->dir.'/'.$template.'.yaml';
        $parsed = is_file($file) ? Yaml::parseFile($file) : [];
        $parsed = \is_array($parsed) ? $parsed : [];
        $parsed['fields'] ??= [];
        $parsed['label'] ??= ucfirst($template);

        // Normalise each field definition.
        foreach ($parsed['fields'] as $path => $def) {
            $def = \is_array($def) ? $def : [];
            $def['type'] ??= 'text';
            $def['label'] ??= $path;
            $parsed['fields'][$path] = $def;
        }

        return $this->cache[$template] = $parsed;
    }

    public function exists(string $template): bool
    {
        return is_file($this->dir.'/'.$template.'.yaml');
    }

    /**
     * Template names a fresh page can be created from in the panel: every page
     * blueprint except `site` (the site-settings form, not a page type) and
     * anything explicitly marked `creatable: false` (a singleton page, e.g.
     * the homepage).
     *
     * @return list<string>
     */
    public function creatable(): array
    {
        $names = [];
        foreach (glob($this->dir.'/*.yaml') ?: [] as $file) {
            $name = basename($file, '.yaml');
            if ('site' === $name || false === ($this->for($name)['creatable'] ?? true)) {
                continue;
            }
            $names[] = $name;
        }
        sort($names);

        return $names;
    }

    /**
     * The skeleton plus any per-template defaults declared under `seed:` in
     * the blueprint (e.g. a default nav_active, or default case.backUrl).
     *
     * @return array<string, mixed>
     */
    public function seeded(string $template): array
    {
        $seed = $this->for($template)['seed'] ?? [];

        return array_replace_recursive($this->skeleton($template), \is_array($seed) ? $seed : []);
    }

    /**
     * An empty document shaped by the blueprint, so a freshly created page has
     * every key its template touches (templates run with strict_variables).
     *
     * @return array<string, mixed>
     */
    public function skeleton(string $template): array
    {
        $doc = [];
        foreach ($this->for($template)['fields'] as $path => $def) {
            $empty = match ($def['type']) {
                'list', 'structure', 'blocks' => [],
                'yaml', 'group' => null, // optional / absent until the editor fills it in
                default => '',
            };
            if (null !== $empty) {
                Dot::set($doc, $path, $empty);
            }
        }

        return $doc;
    }
}
