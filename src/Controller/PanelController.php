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
use Coruja\Cms\Thumbnailer;
use Coruja\Seo\SeoAnalyzer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
        private readonly Thumbnailer $thumbnailer,
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
        $previewFailed = false;

        try {
            $context = $livePage->context();
            $context['nav'] = $this->site->navFor($livePage);
            $html = $this->renderView('main/'.$livePage->template().'.html.twig', $context);
        } catch (\Throwable $e) {
            $previewFailed = true;
            $html = sprintf(
                '<html><head><title>%s</title><meta name="description" content="%s"><meta name="robots" content="%s"></head><body>%s</body></html>',
                htmlspecialchars((string) ($raw['title'] ?? '')),
                htmlspecialchars((string) (Dot::get($raw, 'seo.description') ?? '')),
                htmlspecialchars((string) (Dot::get($raw, 'seo.robots') ?? 'index, follow')),
                htmlspecialchars($this->textFallback($raw)),
            );
        }

        $result = $this->seo->analyze(
            $html,
            $keyphrase,
            $livePage->url(),
            (string) $this->getParameter('app.site_url'),
        );
        $result['preview'] = $html;
        $result['previewFailed'] = $previewFailed;

        return new JsonResponse($result);
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
            return new JsonResponse(['files' => $this->media->allRecursive()]);
        }

        $folder = trim((string) $request->query->get('folder', ''), '/');
        $type = $request->query->get('type');
        $type = \in_array($type, ['image', 'video'], true) ? $type : null;

        $crumbs = [];
        if ('' !== $folder) {
            $parts = explode('/', $folder);
            $acc = [];
            foreach ($parts as $part) {
                $acc[] = $part;
                $crumbs[] = ['name' => $part, 'path' => implode('/', $acc)];
            }
        }

        return $this->render('@Coruja/panel/media.html.twig', [
            'folder' => $folder,
            'type' => $type,
            'crumbs' => $crumbs,
            'folders' => $this->media->folders($folder),
            'files' => $this->media->all($folder, $type),
            'allFolders' => $this->media->allFolders(),
        ]);
    }

    /**
     * A single image's thumbnail, generating it on first request (cached to
     * disk thereafter). This is its own route rather than something computed
     * eagerly for a whole folder listing, precisely so that: (a) generating
     * hundreds of thumbnails can never blow one request's execution-time
     * limit, and (b) combined with the grid's loading="lazy", a thumbnail is
     * only ever generated for an image someone actually scrolls to.
     */
    #[Route('/media/thumb', name: 'panel_media_thumb', methods: ['GET'])]
    public function mediaThumb(Request $request): Response
    {
        $ref = trim((string) $request->query->get('ref', ''), '/');
        if ('' === $ref || str_contains($ref, '..')) {
            throw $this->createNotFoundException();
        }

        $relative = $this->thumbnailer->thumb($ref);
        $absolute = $this->getParameter('kernel.project_dir').'/public/'.$relative;
        if (!is_file($absolute)) {
            throw $this->createNotFoundException();
        }

        // Set the content type explicitly rather than via BinaryFileResponse's
        // default guessing, which needs fileinfo/symfony-mime and isn't
        // guaranteed to be available.
        $contentType = match (strtolower(pathinfo($absolute, \PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'image/jpeg',
        };

        $response = new BinaryFileResponse($absolute);
        $response->headers->set('Content-Type', $contentType);
        $response->setPublic();
        $response->setMaxAge(2592000);

        return $response;
    }

    #[Route('/media', name: 'panel_media_upload', methods: ['POST'])]
    public function mediaUpload(Request $request): Response
    {
        $folder = trim((string) $request->request->get('folder', ''), '/');

        if (!$this->isCsrfTokenValid('panel', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expired — try again.');

            return $this->redirectToRoute('panel_media', ['folder' => $folder]);
        }

        $file = $request->files->get('file');
        if (null === $file) {
            $this->addFlash('error', 'Choose a file first.');

            return $this->redirectToRoute('panel_media', ['folder' => $folder]);
        }

        try {
            $ref = $this->media->store($file, $folder);
            $this->track('added media '.$ref);
            $this->addFlash('success', 'Uploaded '.$ref);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('panel_media', ['folder' => $folder]);
    }

    #[Route('/media/delete', name: 'panel_media_delete', methods: ['POST'])]
    public function mediaDelete(Request $request): Response
    {
        $ref = (string) $request->request->get('ref');
        $folder = \dirname($ref);
        $folder = '.' === $folder ? '' : $folder;

        if ($this->isCsrfTokenValid('panel', (string) $request->request->get('_token'))) {
            $this->media->delete($ref);
            $this->track('removed media '.$ref);
            $this->addFlash('success', 'Deleted.');
        }

        return $this->redirectToRoute('panel_media', ['folder' => $folder]);
    }

    #[Route('/media/move', name: 'panel_media_move', methods: ['POST'])]
    public function mediaMove(Request $request): Response
    {
        $ref = (string) $request->request->get('ref');
        $from = \dirname($ref);
        $from = '.' === $from ? '' : $from;
        $to = trim((string) $request->request->get('to', ''), '/');

        if ($this->isCsrfTokenValid('panel', (string) $request->request->get('_token'))) {
            try {
                $newRef = $this->media->move($ref, $to);
                $pages = $this->content->updateReferences('uploads/'.$ref, 'uploads/'.$newRef);
                $this->track('moved media '.$ref.' -> '.$newRef.($pages ? ' ('.$pages.' page(s) updated)' : ''));
                $this->addFlash('success', 'Moved.'.($pages ? ' Updated '.$pages.' page'.(1 === $pages ? '' : 's').' that referenced it.' : ''));
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('panel_media', ['folder' => $from]);
            }
        }

        return $this->redirectToRoute('panel_media', ['folder' => $to]);
    }

    #[Route('/media/bulk-move', name: 'panel_media_bulk_move', methods: ['POST'])]
    public function mediaBulkMove(Request $request): Response
    {
        $folder = trim((string) $request->request->get('folder', ''), '/');
        $to = trim((string) $request->request->get('to', ''), '/');
        $refs = array_values(array_filter((array) $request->request->all('refs')));

        if ($this->isCsrfTokenValid('panel', (string) $request->request->get('_token')) && $refs) {
            $result = $this->media->moveMany($refs, $to);

            $pagesTouched = 0;
            foreach ($result['renamed'] as $oldRef => $newRef) {
                $pagesTouched += $this->content->updateReferences('uploads/'.$oldRef, 'uploads/'.$newRef);
            }

            $this->track('bulk-moved '.$result['moved'].' file(s) to '.('' === $to ? 'All media' : $to).($pagesTouched ? ' ('.$pagesTouched.' page ref(s) updated)' : ''));
            if ($result['failed']) {
                $this->addFlash('error', \count($result['failed']).' file(s) could not be moved.');
            }
            if ($result['moved']) {
                $this->addFlash('success', 'Moved '.$result['moved'].' file(s).'.($pagesTouched ? ' Updated '.$pagesTouched.' page reference(s).' : ''));
            }

            return $this->redirectToRoute('panel_media', ['folder' => $to]);
        }

        return $this->redirectToRoute('panel_media', ['folder' => $folder]);
    }

    #[Route('/media/bulk-delete', name: 'panel_media_bulk_delete', methods: ['POST'])]
    public function mediaBulkDelete(Request $request): Response
    {
        $folder = trim((string) $request->request->get('folder', ''), '/');
        $refs = array_values(array_filter((array) $request->request->all('refs')));

        if ($this->isCsrfTokenValid('panel', (string) $request->request->get('_token')) && $refs) {
            $count = $this->media->deleteMany($refs);
            $this->track('bulk-deleted '.$count.' file(s)');
            $this->addFlash('success', 'Deleted '.$count.' file(s).');
        }

        return $this->redirectToRoute('panel_media', ['folder' => $folder]);
    }

    #[Route('/media/folders', name: 'panel_media_folder_create', methods: ['POST'])]
    public function mediaFolderCreate(Request $request): Response
    {
        $parent = trim((string) $request->request->get('parent', ''), '/');

        if ($this->isCsrfTokenValid('panel', (string) $request->request->get('_token'))) {
            $name = (string) $request->request->get('name');
            try {
                $path = $this->media->createFolder($parent, $name);
                $this->track('created media folder '.$path);

                return $this->redirectToRoute('panel_media', ['folder' => $path]);
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('panel_media', ['folder' => $parent]);
    }

    #[Route('/media/folders/delete', name: 'panel_media_folder_delete', methods: ['POST'])]
    public function mediaFolderDelete(Request $request): Response
    {
        $path = trim((string) $request->request->get('path', ''), '/');
        $parent = \dirname($path);
        $parent = '.' === $parent ? '' : $parent;

        if ($this->isCsrfTokenValid('panel', (string) $request->request->get('_token'))) {
            try {
                $this->media->deleteFolder($path);
                $this->track('removed media folder '.$path);
                $this->addFlash('success', 'Folder deleted.');
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('panel_media', ['folder' => $path]);
            }
        }

        return $this->redirectToRoute('panel_media', ['folder' => $parent]);
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
