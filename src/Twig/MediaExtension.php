<?php

namespace Coruja\Twig;

use Coruja\Cms\MediaLibrary;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig helpers for pages that pull a live media library folder listing
 * instead of hand-entered content — e.g. a category tab on a portfolio page
 * where the editor organizes photos by dragging them into folders rather
 * than filling out a form row per photo.
 */
final class MediaExtension extends AbstractExtension
{
    public function __construct(
        private readonly MediaLibrary $media,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('media_folder', $this->mediaFolder(...)),
        ];
    }

    /**
     * Files in a media library folder and all of its subfolders, sorted by
     * filename, each with a caption guessed from its filename (e.g.
     * "kitchen-remodel.jpg" -> "Kitchen Remodel") since these photos aren't
     * captioned by hand.
     *
     * @return list<array{path: string, name: string, caption: string}>
     */
    private function mediaFolder(string $folder, string $type = 'image'): array
    {
        $items = $this->media->allRecursive($folder, $type);
        usort($items, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return array_map(static function (array $item): array {
            $base = pathinfo($item['name'], \PATHINFO_FILENAME);
            $spaced = preg_replace('/[-_]+/', ' ', $base) ?? $base;
            $caption = ucwords(trim($spaced));

            return [
                'path' => $item['path'],
                'name' => $item['name'],
                'caption' => $caption,
            ];
        }, $items);
    }
}
