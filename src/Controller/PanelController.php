<?php

namespace Coruja\Controller;

use Coruja\Cms\Blueprints;
use Coruja\Cms\ContentRepository;
use Coruja\Cms\Dot;
use Coruja\Cms\GitSync;
use Coruja\Cms\MediaLibrary;
use Coruja\Cms\Page;
use Coruja\Cms\PageForm;
use Coruja\Cms\Site;
use Coruja\Seo\SeoAnalyzer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The CMS panel. The whole /admin prefix is behind HTTP Basic auth
 * ({@see \Coruja\EventListener\AdminAuthListener}).
 */
#[Route('/admin')]
class PanelController extends AbstractController
{
    private const STATUSES = ['listed', 'unlisted', 'draft'];

    public function __construct(
        private readonly ContentRepository $content,
        private readonly Blueprints $blueprints,
        private readonly PageForm $form,
        private readonly Site $site,
        private readonly MediaLibrary $media,
        private readonly SeoAnalyzer $seo,
        private readonly GitSync $git,
    ) {
    }

    /**
     * Commit a content change to git straight away, so every panel edit is a
     * point in history and the Sync buttons have something to push. Best effort:
     * a checkout without git, or a git hiccup, must never break saving.
     */
    private function track(string $what): void
    {
        try {
            $this->git->commit('content: '.$what);
        } catch (\Throwable) {
            // no-op — the file is already written; git is a convenience here
        }
    }

    #[Route('', name: 'panel_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->render('@Coruja/panel/dashboard.html.twig', [
            'tree' => $this->content->tree(),
            'site' => $this->site->all(),
            'newTemplates' => $this->blueprints->creatable(),
            'sync' => $this->git->status(),
        ]);
    }

    #[Route('/pages', name: 'panel_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('panel', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expired — try again.');

            return $this->redirectToRoute('panel_dashboard');
        }

        $template = (string) $request->request->get('template');
        $path = trim((string) $request->request->get('path'), '/ ');
        $title = trim((string) $request->request->get('title'));

        if (!\in_array($template, $this->blueprints->creatable(), true)) {
            $this->addFlash('error', 'Unknown page type.');

            return $this->redirectToRoute('panel_dashboard');
        }

        $seed = $this->blueprints->seeded($template);

        try {
            $page = $this->content->create($path, $template, $title ?: $path, $seed);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('panel_dashboard');
        }

        $this->track($page->path.' created');
        $this->addFlash('success', 'Page created as a draft.');

        return $this->redirectToRoute('panel_edit', ['path' => $page->path]);
    }

    #[Route('/pages/{path}/delete', name: 'panel_delete', requirements: ['path' => '.+'], methods: ['POST'])]
    public function delete(Request $request, string $path): Response
    {
        $page = $this->content->find($path);
        if (!$page instanceof Page) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('panel', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expired — try again.');

            return $this->redirectToRoute('panel_edit', ['path' => $page->path]);
        }

        try {
            $this->content->delete($page);
            $this->track($page->path.' deleted');
            $this->addFlash('success', sprintf('Deleted “%s”.', $page->title()));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('panel_dashboard');
    }

    #[Route('/pages/{path}/move', name: 'panel_move', requirements: ['path' => '.+'], methods: ['POST'])]
    public function move(Request $request, string $path): Response
    {
        $page = $this->content->find($path);
        if (!$page instanceof Page) {
            throw $this->createNotFoundException();
        }
        if ($this->isCsrfTokenValid('panel', (string) $request->request->get('_token'))) {
            $this->content->move($page, 'up' === $request->request->get('dir') ? -1 : 1);
            $this->track('reordered pages');
        }

        return $this->redirectToRoute('panel_dashboard');
    }

    #[Route('/pages/{path}', name: 'panel_edit', requirements: ['path' => '.+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, string $path): Response
    {
        $page = $this->content->find($path);
        if (!$page instanceof Page) {
            throw $this->createNotFoundException();
        }

        $template = $page->template();
        if (!$this->blueprints->exists($template)) {
            throw $this->createNotFoundException(sprintf('No blueprint for template "%s".', $template));
        }
        $blueprint = $this->blueprints->for($template);

        $errors = [];
        $values = $this->form->values($page->raw, $blueprint);
        $meta = ['status' => $page->status(), 'sort' => $page->sort(), 'nickname' => $page->nickname()];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('panel', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Session expired — nothing saved. Try again.');

                return $this->redirectToRoute('panel_edit', ['path' => $page->path]);
            }

            $submitted = (array) $request->request->all('f');
            $metaIn = (array) $request->request->all('meta');
            $raw = $this->form->apply($page->raw, $blueprint, $submitted, $errors);

            $raw['status'] = \in_array($metaIn['status'] ?? '', self::STATUSES, true) ? $metaIn['status'] : $page->status();
            $raw['sort'] = (int) ($metaIn['sort'] ?? $page->sort());

            $nickname = trim((string) ($metaIn['nickname'] ?? ''));
            if ('' !== $nickname) {
                $raw['nickname'] = $nickname;
            } else {
                unset($raw['nickname']);
            }

            // Re-render with what was typed if anything failed to parse.
            $values = $this->form->values($raw, $blueprint);
            $meta = ['status' => $raw['status'], 'sort' => $raw['sort'], 'nickname' => $nickname];

            if (!$errors) {
                $this->content->save($page, $raw);
                $this->track($page->path.' edited');
                $this->addFlash('success', 'Saved.');

                return $this->redirectToRoute('panel_edit', ['path' => $page->path]);
            }
        }

        return $this->render('@Coruja/panel/edit.html.twig', [
            'page' => $page,
            'blueprint' => $blueprint,
            'values' => $values,
            'meta' => $meta,
            'statuses' => self::STATUSES,
            'errors' => $errors,
        ]);
    }

    /**
     * Live SEO / readability analysis of the *unsaved* form state. Builds the
     * page document from the submitted `f[...]` payload, renders it, and runs
     * {@see SeoAnalyzer} over the resulting HTML.
     */
    #[Route('/pages/{path}/analyze', name: 'panel_analyze', requirements: ['path' => '.+'], methods: ['POST'], priority: 1)]
    public function analyze(Request $request, string $path): JsonResponse
    {
        $page = $this->content->find($path);
        if (!$page instanceof Page || !$this->blueprints->exists($page->template())) {
            throw $this->createNotFoundException();
        }

        $blueprint = $this->blueprints->for($page->template());
        $raw = $this->form->apply($page->raw, $blueprint, (array) $request->request->all('f'));
        $raw['status'] ??= $page->status();

        $keyphrase = (string) (Dot::get($raw, 'seo.focus_keyphrase') ?? '');
        $livePage = new Page($page->path, $raw, $page->file);

        try {
            $context = $livePage->context();
            $context['nav'] = $this->site->navFor($livePage);
            $html = $this->renderView('main/'.$livePage->template().'.html.twig', $context);
        } catch (\Throwable $e) {
            $html = sprintf(
                '<html><head><title>%s</title><meta name="description" content="%s"><meta name="robots" content="%s"></head><body>%s</body></html>',
                htmlspecialchars((string) ($raw['title'] ?? '')),
                htmlspecialchars((string) (Dot::get($raw, 'seo.description') ?? '')),
                htmlspecialchars((string) (Dot::get($raw, 'seo.robots') ?? 'index, follow')),
                htmlspecialchars($this->textFallback($raw)),
            );
        }

        return new JsonResponse($this->seo->analyze(
            $html,
            $keyphrase,
            $livePage->url(),
            (string) $this->getParameter('app.site_url'),
        ));
    }

    /** Best-effort plain text from a document when the template cannot render. */
    private function textFallback(array $raw): string
    {
        $out = [];
        array_walk_recursive($raw, static function ($v) use (&$out): void {
            if (\is_string($v) && mb_strlen(trim($v)) > 0) {
                $out[] = strip_tags($v);
            }
        });

        return implode(' ', $out);
    }

    #[Route('/media', name: 'panel_media', methods: ['GET'])]
    public function mediaIndex(Request $request): Response
    {
        if ('json' === $request->query->get('format')) {
            return new JsonResponse(['files' => $this->media->all()]);
        }

        return $this->render('@Coruja/panel/media.html.twig', ['files' => $this->media->all()]);
    }

    #[Route('/media', name: 'panel_media_upload', methods: ['POST'])]
    public function mediaUpload(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('panel', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expired — try again.');

            return $this->redirectToRoute('panel_media');
        }

        $file = $request->files->get('file');
        if (null === $file) {
            $this->addFlash('error', 'Choose a file first.');

            return $this->redirectToRoute('panel_media');
        }

        try {
            $ref = $this->media->store($file);
            $this->track('added media '.$ref);
            $this->addFlash('success', 'Uploaded '.$ref);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('panel_media');
    }

    #[Route('/media/delete', name: 'panel_media_delete', methods: ['POST'])]
    public function mediaDelete(Request $request): Response
    {
        if ($this->isCsrfTokenValid('panel', (string) $request->request->get('_token'))) {
            $name = (string) $request->request->get('name');
            $this->media->delete($name);
            $this->track('removed media '.$name);
            $this->addFlash('success', 'Deleted.');
        }

        return $this->redirectToRoute('panel_media');
    }

    #[Route('/settings', name: 'panel_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $blueprint = $this->blueprints->for('site');
        $raw = $this->site->all();
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('panel', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Session expired — nothing saved. Try again.');

                return $this->redirectToRoute('panel_settings');
            }

            $raw = $this->form->apply($raw, $blueprint, (array) $request->request->all('f'), $errors);
            if (!$errors) {
                $this->site->save($raw);
                $this->track('site settings');
                $this->addFlash('success', 'Saved.');

                return $this->redirectToRoute('panel_settings');
            }
        }

        return $this->render('@Coruja/panel/settings.html.twig', [
            'blueprint' => $blueprint,
            'values' => $this->form->values($raw, $blueprint),
            'errors' => $errors,
        ]);
    }

    #[Route('/sync/push', name: 'panel_sync_push', methods: ['POST'])]
    public function syncPush(Request $request): Response
    {
        if ($this->isCsrfTokenValid('panel', (string) $request->request->get('_token'))) {
            $result = $this->git->push();
            $this->addFlash($result['ok'] ? 'success' : 'error', $result['message']);
        }

        return $this->redirectToRoute('panel_dashboard');
    }

    #[Route('/sync/pull', name: 'panel_sync_pull', methods: ['POST'])]
    public function syncPull(Request $request): Response
    {
        if ($this->isCsrfTokenValid('panel', (string) $request->request->get('_token'))) {
            $result = $this->git->pull();
            $this->addFlash($result['ok'] ? 'success' : 'error', $result['message']);
        }

        return $this->redirectToRoute('panel_dashboard');
    }
}
