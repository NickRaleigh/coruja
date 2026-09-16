<?php

namespace Coruja\Cms;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generates and caches small JPEG thumbnails for media library images, so
 * the panel's folder grid doesn't have to fetch and decode full-resolution
 * originals (often several MB each) just to show a few hundred pixels of
 * preview — that's what makes scrolling a large folder feel slow. Thumbnails
 * are cached to disk under uploads/.thumbs/ (mirroring the source's folder
 * structure) and regenerated only when missing or older than their source.
 */
final class Thumbnailer
{
    private const MAX_DIMENSION = 480;
    private const JPEG_QUALITY = 78;

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads')]
        private readonly string $uploadsDir,
    ) {
    }

    /**
     * Public asset path for a thumbnail of the image at $ref (relative to
     * uploads/), generating it first if missing or stale. Falls back to the
     * original's own path when thumbnailing isn't possible (GD missing,
     * undecodable source) — never a broken image.
     */
    public function thumb(string $ref): string
    {
        $fallback = 'uploads/'.$ref;
        $source = $this->uploadsDir.'/'.$ref;

        if (!\extension_loaded('gd') || !is_file($source)) {
            return $fallback;
        }

        $thumbRef = 'uploads/.thumbs/'.$ref.'.jpg';
        $thumbPath = $this->uploadsDir.'/.thumbs/'.$ref.'.jpg';

        if (is_file($thumbPath) && filemtime($thumbPath) >= filemtime($source)) {
            return $thumbRef;
        }

        return $this->generate($source, $thumbPath) ? $thumbRef : $fallback;
    }

    private function generate(string $source, string $thumbPath): bool
    {
        // Detect the real format from the file's content rather than trusting
        // its extension — scraped assets sometimes carry a mismatched one
        // (e.g. Squarespace serving WebP bytes under a ".jpg" URL), which
        // would otherwise send them to the wrong imagecreatefrom*() and fail.
        // getimagesize() is core PHP (unlike exif_imagetype(), which needs
        // the ext-exif extension that isn't guaranteed to be installed).
        $info = @getimagesize($source);
        $image = match ($info[2] ?? null) {
            \IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            \IMAGETYPE_PNG => @imagecreatefrompng($source),
            \IMAGETYPE_GIF => @imagecreatefromgif($source),
            \IMAGETYPE_WEBP => \function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default => false,
        };
        if (false === $image) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::MAX_DIMENSION / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        // Flatten any transparency onto white — thumbnails are re-encoded as JPEG.
        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefill($thumb, 0, 0, $white);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        $dir = \dirname($thumbPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($thumb);

            return false;
        }

        $ok = imagejpeg($thumb, $thumbPath, self::JPEG_QUALITY);
        imagedestroy($thumb);

        return $ok;
    }
}
