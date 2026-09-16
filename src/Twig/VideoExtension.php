<?php

namespace Coruja\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Normalizes a pasted YouTube or Vimeo URL into what a template needs to
 * embed it — so an editor can paste whatever their browser's address bar or
 * a "Share" button gave them (a watch page, a share link, or an already
 * proper embed URL) instead of needing to know to hand-construct an
 * "/embed/..." URL.
 */
final class VideoExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('video_info', $this->videoInfo(...)),
        ];
    }

    /**
     * @return array{provider: string|null, id: string|null, embedUrl: string, thumbUrl: string|null, watchUrl: string|null}
     */
    private function videoInfo(string $url): array
    {
        $url = trim($url);

        if (preg_match('#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})#i', $url, $m)) {
            $id = $m[1];

            return [
                'provider' => 'youtube',
                'id' => $id,
                // Already an embed URL (possibly with its own params, e.g. a
                // playlist or start time) — leave it exactly as pasted rather
                // than rebuilding it and losing those. Otherwise (a watch
                // page or youtu.be share link) build the canonical embed URL.
                'embedUrl' => str_contains($url, 'youtube.com/embed/') ? $url : 'https://www.youtube.com/embed/'.$id,
                'thumbUrl' => 'https://img.youtube.com/vi/'.$id.'/mqdefault.jpg',
                'watchUrl' => 'https://www.youtube.com/watch?v='.$id,
            ];
        }

        if (preg_match('#vimeo\.com/(?:video/)?(\d+)#i', $url, $m)) {
            $id = $m[1];
            // Unlisted Vimeo videos need their "h" hash carried into the
            // embed URL, or playback is refused — present on both share
            // links and Vimeo's own embed URLs, so just forward it along.
            $hash = null;
            $query = (string) (parse_url($url, \PHP_URL_QUERY) ?? '');
            if ('' !== $query) {
                parse_str($query, $params);
                $hash = $params['h'] ?? null;
            }

            return [
                'provider' => 'vimeo',
                'id' => $id,
                'embedUrl' => str_contains($url, 'player.vimeo.com/') ? $url : 'https://player.vimeo.com/video/'.$id.($hash ? '?h='.$hash : ''),
                'thumbUrl' => 'https://vumbnail.com/'.$id.'.jpg',
                'watchUrl' => 'https://vimeo.com/'.$id,
            ];
        }

        // Not a recognized YouTube/Vimeo URL — the template falls back to
        // treating it as a plain iframe src (some other provider) or, if it
        // doesn't start with http at all, a local uploads/ file.
        return [
            'provider' => null,
            'id' => null,
            'embedUrl' => $url,
            'thumbUrl' => null,
            'watchUrl' => null,
        ];
    }
}
