<?php

namespace Coruja\Controller;

use Coruja\Cms\AdminAuth;
use Coruja\Cms\ContentRepository;
use Coruja\Cms\Page;
use Coruja\Cms\Site;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders every content page. The URL path maps straight to a file under
 * content/pages/; the page's `template:` key selects the Twig template.
 *
 * The catch-all route runs at a low priority so real routes (/contact,
 * /admin/*, /sitemap.xml, ...) still win.
 */
class PageController extends AbstractController
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly Site $site,
        private readonly AdminAuth $auth,
    ) {
    }

    #[Route('/', name: 'page_home', methods: ['GET'], priority: 10)]
    public function home(Request $request): Response
    {
        return $this->renderPage('home', $request);
    }

    #[Route('/{path}', name: 'page_show', requirements: ['path' => '.+'], methods: ['GET'], priority: -100)]
    public function show(string $path, Request $request): Response
    {
        return $this->renderPage($path, $request);
    }

    private function renderPage(string $path, Request $request): Response
    {
        $page = $this->content->find($path);
        if (!$page instanceof Page) {
            throw $this->createNotFoundException();
        }

        // Draft pages are hidden unless an authenticated editor is previewing.
        if ('draft' === $page->status() && !$this->auth->check($request)) {
            throw $this->createNotFoundException();
        }

        $context = $page->context();
        $context['nav'] = $this->site->navFor($page);
        $context['seo'] = $this->resolveSeo($context['seo'] ?? []);

        return $this->render('main/'.$page->template().'.html.twig', $context);
    }

    /**
     * Absolutise a relative seo.image against the site URL so base.html.twig
     * can emit it as an og:image unchanged.
     *
     * @param array<string, mixed> $seo
     *
     * @return array<string, mixed>
     */
    private function resolveSeo(array $seo): array
    {
        if (isset($seo['image']) && \is_string($seo['image']) && '' !== $seo['image'] && !str_starts_with($seo['image'], 'http')) {
            $base = rtrim((string) $this->getParameter('app.site_url'), '/');
            $seo['image'] = $base.'/'.ltrim($seo['image'], '/');
        }

        return $seo;
    }
}
