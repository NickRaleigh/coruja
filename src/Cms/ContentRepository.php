<?php

namespace Coruja\Cms;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads (and, from the panel, writes) the flat-file content tree under content/pages/.
 *
 * The file path relative to content/pages/, minus the .yaml extension, is the page's
 * public URL path. content/pages/work/overture.yaml -> "/work/overture".
 * content/pages/home.yaml is special-cased to "/".
 */
final class ContentRepository
{
    private readonly string $pagesDir;

    /** @var array<string, Page|null> */
    private array $cache = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/content')]
        private readonly string $contentDir,
    ) {
        $this->pagesDir = $this->contentDir.'/pages';
    }

    public function find(string $path): ?Page
    {
        $path = trim($path, '/');
        $path = '' === $path ? 'home' : $path;

        if (\array_key_exists($path, $this->cache)) {
            return $this->cache[$path];
        }

        if (!$this->isSafePath($path)) {
            return $this->cache[$path] = null;
        }

        $file = $this->pagesDir.'/'.$path.'.yaml';
        if (!is_file($file)) {
            return $this->cache[$path] = null;
        }

        $raw = Yaml::parseFile($file);
        $raw = \is_array($raw) ? $raw : [];

        return $this->cache[$path] = new Page($path, $raw, $file);
    }

    /**
     * Every page in the tree, ordered by (sort, path).
     *
     * @return list<Page>
     */
    public function all(): array
    {
        if (!is_dir($this->pagesDir)) {
            return [];
        }

        $pages = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->pagesDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if (!$entry->isFile() || 'yaml' !== $entry->getExtension()) {
                continue;
            }
            $relative = substr($entry->getPathname(), \strlen($this->pagesDir) + 1, -5);
            $page = $this->find(str_replace(\DIRECTORY_SEPARATOR, '/', $relative));
            if ($page) {
                $pages[] = $page;
            }
        }

        usort($pages, static fn (Page $a, Page $b) => [$a->sort(), $a->path] <=> [$b->sort(), $b->path]);

        return array_values($pages);
    }

    /**
     * The page list flattened into display order for the panel: top-level pages
     * and one-deep folders, each folder header followed by its child pages.
     *
     * @return list<array{
     *     page: Page|null, path: string, title: string, url: string,
     *     depth: int, folder: bool, first: bool, last: bool
     * }>
     */
    public function tree(): array
    {
        $pages = $this->all(); // already ordered by (sort, path)

        $topLevel = [];
        $sections = []; // section slug => list<Page>
        foreach ($pages as $page) {
            if (str_contains($page->path, '/')) {
                $sections[substr($page->path, 0, strpos($page->path, '/'))][] = $page;
            } else {
                $topLevel[] = $page;
            }
        }

        $rows = [];

        $appendChildren = static function (string $slug) use (&$rows, $sections): void {
            $kids = $sections[$slug] ?? [];
            $lastKid = \count($kids) - 1;
            foreach ($kids as $i => $child) {
                $rows[] = [
                    'page' => $child, 'path' => $child->path, 'title' => $child->label(),
                    'url' => $child->url(), 'depth' => 1, 'folder' => false,
                    'first' => 0 === $i, 'last' => $i === $lastKid,
                ];
            }
        };

        // One ordered stream of top-level entries: real pages, plus any section
        // slug that has no page of its own (a bare folder like /work). A bare
        // folder sorts where its first child would.
        $entries = [];
        foreach ($topLevel as $page) {
            $entries[] = ['sort' => $page->sort(), 'path' => $page->path, 'page' => $page];
        }
        foreach ($sections as $slug => $kids) {
            if (null === $this->find($slug)) {
                $entries[] = ['sort' => $kids[0]->sort(), 'path' => $slug, 'page' => null];
            }
        }
        usort($entries, static fn ($a, $b) => [$a['sort'], $a['path']] <=> [$b['sort'], $b['path']]);

        $lastIndex = \count($entries) - 1;
        foreach ($entries as $i => $entry) {
            $slug = $entry['path'];
            $page = $entry['page'];
            $hasKids = isset($sections[$slug]);

            $rows[] = [
                'page' => $page,
                'path' => $slug,
                'title' => $page?->label() ?? ucfirst($slug),
                'url' => $page?->url() ?? '/'.$slug,
                'depth' => 0,
                'folder' => null === $page,
                'first' => 0 === $i,
                'last' => !$hasKids && $i === $lastIndex,
            ];

            if ($hasKids) {
                $appendChildren($slug);
            }
        }

        return $rows;
    }

    /**
     * Direct children of a page path ('' / 'home' = top level).
     *
     * @return list<Page>
     */
    public function children(string $parent): array
    {
        $parent = trim($parent, '/');
        $parent = 'home' === $parent ? '' : $parent;
        $prefix = '' === $parent ? '' : $parent.'/';
        $depth = '' === $prefix ? 0 : substr_count($prefix, '/');

        return array_values(array_filter($this->all(), static function (Page $page) use ($prefix, $depth) {
            if ('home' === $page->path) {
                return false;
            }
            if ('' !== $prefix && !str_starts_with($page->path, $prefix)) {
                return false;
            }

            return substr_count($page->path, '/') === $depth;
        }));
    }

    /**
     * Write a page back to disk (atomic: temp file + rename).
     *
     * @param array<string, mixed> $raw the full page document
     */
    public function save(Page $page, array $raw): void
    {
        $yaml = Yaml::dump($raw, 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        $tmp = $page->file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (false === file_put_contents($tmp, $yaml, \LOCK_EX)) {
            throw new \RuntimeException('Could not write '.$tmp);
        }
        if (!rename($tmp, $page->file)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not replace '.$page->file);
        }

        unset($this->cache[$page->path]);
    }

    /**
     * Scaffold a new page. Throws if the path is taken or malformed.
     *
     * @param array<string, mixed> $seed extra keys to merge into the document
     */
    public function create(string $path, string $template, string $title, array $seed = []): Page
    {
        $path = trim($path, '/');
        if (!$this->isSafePath($path) || 'home' === $path) {
            throw new \InvalidArgumentException('That URL path is not allowed.');
        }
        $file = $this->pagesDir.'/'.$path.'.yaml';
        if (is_file($file)) {
            throw new \InvalidArgumentException('A page already lives at that path.');
        }

        $raw = [
            'template' => $template,
            'status' => 'draft',
            'sort' => $this->nextSort(),
            'title' => $title,
        ];
        foreach ($seed as $key => $value) {
            if (!\array_key_exists($key, $raw)) {
                $raw[$key] = $value;
            }
        }

        if (!is_dir(\dirname($file)) && !mkdir($concurrent = \dirname($file), 0775, true) && !is_dir($concurrent)) {
            throw new \RuntimeException('Could not create '.\dirname($file));
        }

        $page = new Page($path, $raw, $file);
        $this->save($page, $raw);

        return $page;
    }

    /** Delete a page file and prune now-empty parent folders. */
    public function delete(Page $page): void
    {
        if ('home' === $page->path) {
            throw new \InvalidArgumentException('The homepage cannot be deleted.');
        }
        @unlink($page->file);
        unset($this->cache[$page->path]);

        $dir = \dirname($page->file);
        while ($dir !== $this->pagesDir && is_dir($dir) && !(new \FilesystemIterator($dir))->valid()) {
            @rmdir($dir);
            $dir = \dirname($dir);
        }
    }

    /**
     * Nudge a page one slot up or down among its siblings (same parent folder),
     * by swapping sort values.
     */
    public function move(Page $page, int $direction): void
    {
        $parent = str_contains($page->path, '/') ? substr($page->path, 0, strrpos($page->path, '/')) : '';
        $siblings = array_values(array_filter(
            $this->all(),
            static fn (Page $p) => 'home' !== $p->path
                && (str_contains($p->path, '/') ? substr($p->path, 0, strrpos($p->path, '/')) : '') === $parent,
        ));

        $index = null;
        foreach ($siblings as $i => $sibling) {
            if ($sibling->path === $page->path) {
                $index = $i;
                break;
            }
        }
        if (null === $index) {
            return;
        }
        $target = $index + ($direction < 0 ? -1 : 1);
        if ($target < 0 || $target >= \count($siblings)) {
            return;
        }

        $a = $siblings[$index];
        $b = $siblings[$target];
        $aSort = $a->sort();
        $bSort = $b->sort();
        if ($aSort === $bSort) {
            $bSort = $aSort + ($direction < 0 ? -1 : 1);
        }

        $rawA = $a->raw;
        $rawA['sort'] = $bSort;
        $rawB = $b->raw;
        $rawB['sort'] = $aSort;
        $this->save($a, $rawA);
        $this->save($b, $rawB);
    }

    private function nextSort(): int
    {
        $max = 0;
        foreach ($this->all() as $page) {
            $max = max($max, $page->sort());
        }

        return $max + 1;
    }

    /** Guards against traversal and stray path characters. */
    private function isSafePath(string $path): bool
    {
        return '' !== $path
            && !str_contains($path, '..')
            && 1 === preg_match('#^[a-z0-9][a-z0-9/_-]*$#', $path)
            && !str_contains($path, '//');
    }
}
