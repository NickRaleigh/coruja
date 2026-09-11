<?php

namespace Coruja\Cms;

/**
 * A single content page, parsed from content/pages/<path>.yaml.
 *
 * Meta keys (template, status, sort, nav_active) drive routing and the panel;
 * everything else is passed straight through to the Twig template.
 */
final class Page
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $path,
        public readonly array $raw,
        public readonly string $file,
    ) {
    }

    public function template(): string
    {
        return (string) ($this->raw['template'] ?? 'page');
    }

    public function status(): string
    {
        return (string) ($this->raw['status'] ?? 'listed');
    }

    public function sort(): int
    {
        return (int) ($this->raw['sort'] ?? 0);
    }

    public function navActive(): ?string
    {
        return isset($this->raw['nav_active']) ? (string) $this->raw['nav_active'] : null;
    }

    public function isHome(): bool
    {
        return 'home' === $this->path;
    }

    public function title(): string
    {
        return (string) ($this->raw['title'] ?? $this->path);
    }

    /**
     * A short name for lists and menus in the panel. Uses the explicit
     * `nickname` when set, otherwise the part of the title before the first
     * "·" separator, otherwise the path.
     */
    public function label(): string
    {
        $nickname = trim((string) ($this->raw['nickname'] ?? ''));
        if ('' !== $nickname) {
            return $nickname;
        }

        $head = trim((string) (preg_split('/\s*[·|]\s*/u', $this->title(), 2)[0] ?? ''));

        return '' !== $head ? $head : $this->path;
    }

    public function nickname(): string
    {
        return trim((string) ($this->raw['nickname'] ?? ''));
    }

    /** The public URL path for this page ('/' for home). */
    public function url(): string
    {
        return $this->isHome() ? '/' : '/'.$this->path;
    }

    /**
     * Template context: the raw data minus the meta keys the templates don't use.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $ctx = $this->raw;
        unset($ctx['template'], $ctx['status'], $ctx['sort'], $ctx['nav_active'], $ctx['nickname']);

        return $ctx;
    }
}
