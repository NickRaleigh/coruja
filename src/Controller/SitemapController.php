<?php

namespace Coruja\Controller;

use Coruja\Cms\ContentRepository;
use Coruja\Cms\Dot;
use Coruja\Cms\Site;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(RouterInterface $router, ContentRepository $content, Site $site, Request $request): Response
    {
        $base = rtrim($this->getParameter('app.site_url') ?: $request->getSchemeAndHttpHost(), '/');

        $skip = ['sitemap', 'robots', 'llms'];
        if ('on' !== $site->get('seo_check', 'off')) {
            $skip[] = 'seo_check';
        }

        $urls = [];
        $lastmodTs = 0;

        // Explicit, non-dynamic App routes (e.g. /contact).
        foreach ($router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();

            // Skip anything dynamic, internal, admin, or non-page.
            if (str_contains($path, '{') || str_starts_with($path, '/_') || str_starts_with($path, '/admin')) {
                continue;
            }
            if (in_array($name, $skip, true)) {
                continue;
            }
            $methods = $route->getMethods();
            if ($methods && !in_array('GET', $methods, true)) {
                continue;
            }
            $controller = $route->getDefault('_controller') ?? '';
            if (!str_starts_with((string) $controller, 'App\\')) {
                continue;
            }

            $urls[$path] = [
                'loc' => $base.$path,
                'priority' => '/' === $path ? '1.0' : '0.7',
            ];
        }

        // Content pages from the flat-file tree. Everything indexable goes in,
        // including 'unlisted' pages (hidden from nav/listing but still meant
        // to be found and ranked) — only 'draft' pages are excluded.
        foreach ($content->all() as $page) {
            if ('draft' === $page->status()) {
                continue;
            }
            $path = $page->url();
            $urls[$path] = [
                'loc' => $base.$path,
                'priority' => '/' === $path ? '1.0' : '0.7',
            ];
            $lastmodTs = max($lastmodTs, (int) @filemtime($page->file));
        }

        $lastmod = date('Y-m-d', $lastmodTs ?: time());

        ksort($urls);

        $response = new Response(
            $this->renderView('sitemap.xml.twig', ['urls' => array_values($urls), 'lastmod' => $lastmod])
        );
        $response->headers->set('Content-Type', 'application/xml; charset=utf-8');

        return $response;
    }

    #[Route('/robots.txt', name: 'robots', methods: ['GET'])]
    public function robots(Request $request): Response
    {
        $base = rtrim($this->getParameter('app.site_url') ?: $request->getSchemeAndHttpHost(), '/');

        $body = "User-agent: *\nAllow: /\nDisallow: /admin/\n\nSitemap: {$base}/sitemap.xml\n";

        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * llms.txt — a curated, machine-readable overview of the site for LLMs.
     * Built from the content tree; the summary and intro come from Site settings.
     *
     * @see https://llmstxt.org
     */
    #[Route('/llms.txt', name: 'llms', methods: ['GET'])]
    public function llms(ContentRepository $content, Site $site, Request $request): Response
    {
        $base = rtrim($this->getParameter('app.site_url') ?: $request->getSchemeAndHttpHost(), '/');

        $lines = ['# Nick Raleigh', ''];

        if ('' !== ($summary = trim((string) $site->get('llms_summary', '')))) {
            $lines[] = '> '.$summary;
            $lines[] = '';
        }
        if ('' !== ($intro = trim((string) $site->get('llms_intro', '')))) {
            $lines[] = $intro;
            $lines[] = '';
        }

        $lines[] = '## Pages';
        $lines[] = '';
        foreach ($content->all() as $page) {
            if ('draft' === $page->status()) {
                continue;
            }
            $label = trim(explode('·', $page->title())[0]);
            $desc = trim((string) Dot::get($page->raw, 'seo.description'));
            $url = $base.$page->url();
            $lines[] = '' !== $desc ? "- [{$label}]({$url}): {$desc}" : "- [{$label}]({$url})";
        }
        $lines[] = "- [Contact]({$base}/contact): get in touch about a technical SEO audit or a web development project";
        $lines[] = '';

        return new Response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
