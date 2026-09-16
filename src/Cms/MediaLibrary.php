<?php

namespace Coruja\Cms;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A folder-organised media library at public/uploads/. Files (and folders)
 * are referenced from content as "uploads/<path>" — the same shape templates
 * already pass to Twig's asset().
 *
 * Folders are real directories under public/uploads/. Root-level files (no
 * folder) behave exactly as before this class supported folders at all —
 * existing "uploads/<name>" references made before folders existed keep
 * working unchanged.
 *
 * Holds both images and video: each listed entry carries a `type` ('image' or
 * 'video') so templates can render a thumbnail or a player, and so callers can
 * filter by kind. Videos get a much larger size cap than images — the app is
 * expected to keep video extensions out of its git history (they're too big
 * for that) via .gitignore, independent of this class.
 */
final class MediaLibrary
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];
    private const VIDEO_EXT = ['mp4', 'mov', 'webm', 'm4v'];
    private const IMAGE_MAX_BYTES = 8 * 1024 * 1024;
    private const VIDEO_MAX_BYTES = 2 * 1024 * 1024 * 1024;

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads')]
        private readonly string $dir,
    ) {
    }

    private static function typeFor(string $ext): ?string
    {
        $ext = strtolower($ext);
        if (\in_array($ext, self::IMAGE_EXT, true)) {
            return 'image';
        }
        if (\in_array($ext, self::VIDEO_EXT, true)) {
            return 'video';
        }

        return null;
    }

    /**
     * Immediate subfolders of $folder ('' = root), each with its file count
     * (files only, not recursive into grandchildren).
     *
     * @return list<array{name: string, path: string, count: int}>
     */
    public function folders(string $folder = ''): array
    {
        $base = $this->resolve($folder);
        if (null === $base || !is_dir($base)) {
            return [];
        }

        $out = [];
        foreach (scandir($base) ?: [] as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $full = $base.'/'.$name;
            if (!is_dir($full)) {
                continue;
            }
            $count = 0;
            foreach (scandir($full) ?: [] as $entry) {
                $entryPath = $full.'/'.$entry;
                if (is_file($entryPath) && null !== self::typeFor(pathinfo($entry, \PATHINFO_EXTENSION))) {
                    ++$count;
                }
            }
            $out[] = [
                'name' => $name,
                'path' => '' === $folder ? $name : $folder.'/'.$name,
                'count' => $count,
            ];
        }
        usort($out, static fn ($a, $b) => $a['name'] <=> $b['name']);

        return $out;
    }

    /**
     * Files directly inside $folder ('' = root) — not recursive. Pass $type
     * ('image' or 'video') to filter; null returns both.
     *
     * @return list<array{name: string, path: string, ref: string, type: string, size: int, modified: int}>
     */
    public function all(string $folder = '', ?string $type = null): array
    {
        $base = $this->resolve($folder);
        if (null === $base || !is_dir($base)) {
            return [];
        }

        $out = [];
        foreach (scandir($base) ?: [] as $name) {
            $full = $base.'/'.$name;
            if (!is_file($full)) {
                continue;
            }
            $fileType = self::typeFor(pathinfo($name, \PATHINFO_EXTENSION));
            if (null === $fileType || (null !== $type && $type !== $fileType)) {
                continue;
            }
            $ref = '' === $folder ? $name : $folder.'/'.$name;
            $out[] = [
                'name' => $name,
                'path' => 'uploads/'.$ref,
                'ref' => $ref,
                'type' => $fileType,
                'size' => (int) filesize($full),
                'modified' => (int) filemtime($full),
            ];
        }
        usort($out, static fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return $out;
    }

    /**
     * Every file under $folder ('' = the whole library), including
     * subfolders at any depth. Used by the content-field image picker
     * ($folder omitted, $type left at its 'image' default — "any image,
     * pick one") and by pages that want everything nested under one folder,
     * e.g. a portfolio category that should also pick up shots an editor
     * dropped into a "Behind the Scenes" subfolder. Pass $type = null to
     * include video too.
     *
     * @return list<array{name: string, path: string, ref: string, type: string, size: int, modified: int}>
     */
    public function allRecursive(string $folder = '', ?string $type = 'image'): array
    {
        $base = $this->resolve($folder);
        if (null === $base || !is_dir($base)) {
            return [];
        }

        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if (!$entry->isFile()) {
                continue;
            }
            $fileType = self::typeFor($entry->getExtension());
            if (null === $fileType || (null !== $type && $type !== $fileType)) {
                continue;
            }
            $ref = str_replace(\DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), \strlen($this->dir) + 1));
            $out[] = [
                'name' => $entry->getFilename(),
                'path' => 'uploads/'.$ref,
                'ref' => $ref,
                'type' => $fileType,
                'size' => (int) $entry->getSize(),
                'modified' => (int) $entry->getMTime(),
            ];
        }
        usort($out, static fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return $out;
    }

    /**
     * Every folder path in the library, flattened, for a "move to…" picker.
     *
     * @return list<string>
     */
    public function allFolders(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isDir()) {
                $out[] = str_replace(\DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), \strlen($this->dir) + 1));
            }
        }
        sort($out);

        return $out;
    }

    /** Store an upload into $folder ('' = root), returning its "uploads/<path>" reference. */
    public function store(UploadedFile $file, string $folder = ''): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: '');
        $type = self::typeFor($ext);
        if (null === $type) {
            throw new \InvalidArgumentException('Only images ('.implode(', ', self::IMAGE_EXT).') or video ('.implode(', ', self::VIDEO_EXT).') are allowed.');
        }
        $cap = 'image' === $type ? self::IMAGE_MAX_BYTES : self::VIDEO_MAX_BYTES;
        if ($file->getSize() > $cap) {
            throw new \InvalidArgumentException('That file is larger than '.($cap / (1024 * 1024)).' MB.');
        }

        $base = $this->resolve($folder);
        if (null === $base) {
            throw new \InvalidArgumentException('That folder path is not allowed.');
        }
        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            throw new \RuntimeException('Could not create the uploads folder.');
        }

        $stem = pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME);
        $stem = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $stem) ?? '', '-')) ?: 'file';

        $name = $stem.'.'.$ext;
        for ($i = 2; is_file($base.'/'.$name); ++$i) {
            $name = $stem.'-'.$i.'.'.$ext;
        }

        try {
            $file->move($base, $name);
        } catch (FileException $e) {
            throw new \RuntimeException('Upload failed: '.$e->getMessage());
        }

        return 'uploads/'.('' === $folder ? $name : $folder.'/'.$name);
    }

    /** Create a subfolder of $parent ('' = root). Returns its folder-relative path. */
    public function createFolder(string $parent, string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
        if ('' === $slug) {
            throw new \InvalidArgumentException('That folder name is not allowed.');
        }

        $parentDir = $this->resolve($parent);
        if (null === $parentDir) {
            throw new \InvalidArgumentException('That parent folder path is not allowed.');
        }

        $path = '' === $parent ? $slug : $parent.'/'.$slug;
        $full = $this->resolve($path);
        if (null === $full) {
            throw new \InvalidArgumentException('That folder path is not allowed.');
        }
        if (is_dir($full)) {
            throw new \InvalidArgumentException('A folder already exists there.');
        }
        if (!mkdir($full, 0775, true) && !is_dir($full)) {
            throw new \RuntimeException('Could not create '.$full);
        }

        return $path;
    }

    /** Delete a folder. Refuses if it still holds anything, to avoid an accidental mass-delete. */
    public function deleteFolder(string $path): void
    {
        $full = $this->resolve($path);
        if (null === $full || '' === $path || !is_dir($full)) {
            return;
        }
        if ((new \FilesystemIterator($full))->valid()) {
            throw new \InvalidArgumentException('That folder is not empty.');
        }
        @rmdir($full);
    }

    /** Move a file (by its folder-relative ref) into a different folder. Returns its new ref. */
    public function move(string $ref, string $toFolder): string
    {
        $from = $this->resolveFile($ref);
        $toDir = $this->resolve($toFolder);
        if (null === $from || null === $toDir || !is_file($from)) {
            throw new \InvalidArgumentException('That move is not allowed.');
        }
        if (!is_dir($toDir) && !mkdir($toDir, 0775, true) && !is_dir($toDir)) {
            throw new \RuntimeException('Could not create '.$toDir);
        }

        $name = basename($from);
        $candidate = $name;
        $i = 2;
        while (is_file($toDir.'/'.$candidate)) {
            $candidate = pathinfo($name, \PATHINFO_FILENAME).'-'.$i.'.'.pathinfo($name, \PATHINFO_EXTENSION);
            ++$i;
        }

        if (!rename($from, $toDir.'/'.$candidate)) {
            throw new \RuntimeException('Could not move the file.');
        }

        return '' === $toFolder ? $candidate : $toFolder.'/'.$candidate;
    }

    /**
     * Move several files into a folder in one go. Best-effort: a ref that
     * fails to move is skipped rather than aborting the rest.
     *
     * @param list<string> $refs
     *
     * @return array{moved: int, failed: list<string>, renamed: array<string, string>} renamed: old ref => new ref, successes only
     */
    public function moveMany(array $refs, string $toFolder): array
    {
        $moved = 0;
        $failed = [];
        $renamed = [];
        foreach ($refs as $ref) {
            try {
                $renamed[$ref] = $this->move($ref, $toFolder);
                ++$moved;
            } catch (\InvalidArgumentException|\RuntimeException) {
                $failed[] = $ref;
            }
        }

        return ['moved' => $moved, 'failed' => $failed, 'renamed' => $renamed];
    }

    /** Delete a file by its folder-relative ref (e.g. "pioneers/erika.jpg", or just "erika.jpg" at root). */
    public function delete(string $ref): void
    {
        $full = $this->resolveFile($ref);
        if (null !== $full && is_file($full)) {
            @unlink($full);
        }
    }

    /**
     * Delete several files by ref in one go.
     *
     * @param list<string> $refs
     */
    public function deleteMany(array $refs): int
    {
        $count = 0;
        foreach ($refs as $ref) {
            $full = $this->resolveFile($ref);
            if (null !== $full && is_file($full)) {
                @unlink($full);
                ++$count;
            }
        }

        return $count;
    }

    /** Resolve a folder path to an absolute directory, or null if unsafe. '' means the uploads root. */
    private function resolve(string $path): ?string
    {
        $path = trim($path, '/');
        if ('' === $path) {
            return $this->dir;
        }
        if (!$this->isSafePath($path)) {
            return null;
        }

        return $this->dir.'/'.$path;
    }

    /** Resolve a file ref (folder-relative path) to an absolute path, or null if unsafe. */
    private function resolveFile(string $ref): ?string
    {
        $ref = ltrim($ref, '/');
        if ('' === $ref || !$this->isSafePath($ref)) {
            return null;
        }

        return $this->dir.'/'.$ref;
    }

    /** Guards against traversal and stray path characters, segment by segment. */
    private function isSafePath(string $path): bool
    {
        if (str_contains($path, '..') || str_contains($path, '//') || str_starts_with($path, '.')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || 1 !== preg_match('#^[a-zA-Z0-9][a-zA-Z0-9_.-]*$#', $segment)) {
                return false;
            }
        }

        return true;
    }
}
