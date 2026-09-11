<?php

namespace Coruja\Controller;

use Coruja\Cms\Site;
use Coruja\Tool\SiteChecker;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * /seo-check — a free, no-signup surface-level SEO checker. Lead magnet for the
 * audit package.
 */
class SeoCheckController extends AbstractController
{
    private const HOURLY_LIMIT = 12;

    public function __construct(
        private readonly SiteChecker $checker,
        private readonly CacheItemPoolInterface $cache,
        private readonly Site $site,
    ) {
    }

    /** Off until the checks are polished. Flip on in /admin/settings ("SEO check tool"). */
    private function enabled(): bool
    {
        return 'on' === $this->site->get('seo_check', 'off');
    }

    #[Route('/seo-check', name: 'seo_check', methods: ['GET'])]
    public function page(): Response
    {
        if (!$this->enabled()) {
            throw $this->createNotFoundException();
        }

        return $this->render('main/seo-check.html.twig', [
            'title' => 'Free Instant SEO Check · Nick Raleigh',
            'seo' => [
                'description' => 'Enter a URL for a free, instant check of the SEO basics: HTTPS, mobile viewport, title and meta tags, structured data, speed, and indexability. No email required.',
            ],
            'nav' => $this->site->nav('seo-check'),
        ]);
    }

    #[Route('/seo-check', name: 'seo_check_run', methods: ['POST'])]
    public function run(Request $request): JsonResponse
    {
        if (!$this->enabled()) {
            throw $this->createNotFoundException();
        }

        $ip = (string) $request->getClientIp();
        $item = $this->cache->getItem('seocheck_'.sha1($ip));
        $count = (int) $item->get();
        if ($count >= self::HOURLY_LIMIT) {
            return new JsonResponse(
                ['ok' => false, 'error' => 'You have run a lot of checks in the last hour. Try again later, or get in touch for a full audit.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }
        $item->set($count + 1)->expiresAfter(3600);
        $this->cache->save($item);

        $url = (string) ($request->request->get('url') ?? $request->toArray()['url'] ?? '');
        if (mb_strlen($url) > 300) {
            return new JsonResponse(['ok' => false, 'error' => 'That URL is too long.'], 422);
        }

        return new JsonResponse($this->checker->check($url));
    }
}
