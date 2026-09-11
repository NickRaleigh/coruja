<?php

namespace Coruja\Cms;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Site-wide settings from content/site.yaml (name, email, primary nav).
 */
final class Site
{
    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/content/site.yaml')]
        private readonly string $file,
    ) {
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if (null === $this->data) {
            $parsed = is_file($this->file) ? Yaml::parseFile($this->file) : [];
            $this->data = \is_array($parsed) ? $parsed : [];
        }

        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** @param array<string, mixed> $data */
    public function save(array $data): void
    {
        $yaml = Yaml::dump($data, 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        $tmp = $this->file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (false === file_put_contents($tmp, $yaml, \LOCK_EX) || !rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write '.$this->file);
        }
        $this->data = null;
    }

    /**
     * Interior-page nav: type "link" throughout, one item flagged active.
     *
     * @return list<array<string, string|bool>>
     */
    public function nav(?string $active = null): array
    {
        $out = [];
        foreach ($this->navItems() as $item) {
            $out[] = [
                'name' => $item['name'],
                'title' => $item['title'],
                'type' => 'link',
                'target' => $item['target'],
                'active' => null !== $active && $item['name'] === $active,
            ];
        }

        return $out;
    }

    /**
     * Homepage nav: "/#anchor" targets become in-page scroll links.
     *
     * @return list<array<string, string>>
     */
    public function homeNav(): array
    {
        $out = [];
        foreach ($this->navItems() as $item) {
            if (str_starts_with($item['target'], '/#')) {
                $out[] = [
                    'name' => $item['name'],
                    'title' => $item['title'],
                    'type' => 'scrollTo',
                    'target' => substr($item['target'], 2),
                ];
            } else {
                $out[] = [
                    'name' => $item['name'],
                    'title' => $item['title'],
                    'type' => 'link',
                    'target' => $item['target'],
                ];
            }
        }

        return $out;
    }

    /**
     * Nav appropriate for a given page.
     *
     * @return list<array<string, string|bool>>
     */
    public function navFor(Page $page): array
    {
        return $page->isHome() ? $this->homeNav() : $this->nav($page->navActive());
    }

    /** @return list<array{name: string, title: string, target: string}> */
    private function navItems(): array
    {
        $items = $this->get('nav', []);
        $out = [];
        foreach (\is_array($items) ? $items : [] as $item) {
            $out[] = [
                'name' => (string) ($item['name'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
                'target' => (string) ($item['target'] ?? ''),
            ];
        }

        return $out;
    }
}
