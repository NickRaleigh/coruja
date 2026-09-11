<?php

namespace Coruja\Cms;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A flat media folder at public/uploads/. Files are referenced from content as
 * "uploads/<name>" — the same shape templates already pass to Twig's asset().
 */
final class MediaLibrary
{
    private const EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads')]
        private readonly string $dir,
    ) {
    }

    /**
     * @return list<array{name: string, path: string, size: int, modified: int}>
     */
    public function all(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $out = [];
        foreach (scandir($this->dir) ?: [] as $name) {
            $full = $this->dir.'/'.$name;
            if (!is_file($full) || !\in_array(strtolower(pathinfo($name, \PATHINFO_EXTENSION)), self::EXT, true)) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'path' => 'uploads/'.$name,
                'size' => (int) filesize($full),
                'modified' => (int) filemtime($full),
            ];
        }
        usort($out, static fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return $out;
    }

    /** Store an upload, returning its "uploads/<name>" reference. */
    public function store(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: '');
        if (!\in_array($ext, self::EXT, true)) {
            throw new \InvalidArgumentException('Only image files are allowed ('.implode(', ', self::EXT).').');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('That file is larger than 8 MB.');
        }

        $base = pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME);
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $base) ?? '', '-')) ?: 'image';

        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Could not create the uploads folder.');
        }

        $name = $base.'.'.$ext;
        for ($i = 2; is_file($this->dir.'/'.$name); ++$i) {
            $name = $base.'-'.$i.'.'.$ext;
        }

        try {
            $file->move($this->dir, $name);
        } catch (FileException $e) {
            throw new \RuntimeException('Upload failed: '.$e->getMessage());
        }

        return 'uploads/'.$name;
    }

    public function delete(string $name): void
    {
        $name = basename($name);
        $full = $this->dir.'/'.$name;
        if (is_file($full)) {
            @unlink($full);
        }
    }
}
