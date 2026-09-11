# Coruja

A small Kirby-style flat-file CMS, extracted from nickraleigh.com so more than
one site can share it. Git-backed YAML content, a blueprint-driven admin
panel with repeaters and a Bard-style blocks field, on-page SEO/readability
analysis, and an SSRF-guarded external URL checker.

## What this bundle provides

- `Coruja\Cms\*` — ContentRepository, Site, Page, PageForm,
  Blueprints, MediaLibrary, GitSync, Dot, AdminAuth
- `Coruja\Seo\SeoAnalyzer`, `Tool\SiteChecker`
- `Controller\PanelController` (the whole `/admin` panel), `PageController`
  (catch-all page render), `SitemapController` (`/sitemap.xml`, `/robots.txt`,
  `/llms.txt`), `SeoCheckController` (`/seo-check`)
- `EventListener\AdminAuthListener` — HTTP Basic gate on `/admin`
- The panel's Twig templates, namespaced `@Coruja` (e.g.
  `@Coruja/panel/dashboard.html.twig`)

## What a site provides

This bundle deliberately owns nothing about how a site looks or what its
pages contain. A consuming app supplies:

- `content/**/*.yaml` and `config/blueprints/*.yaml` — the actual content and
  content model
- `templates/main/*.html.twig`, `templates/base.html.twig` — page rendering.
  `PageController` renders `main/<page's template>.html.twig`; that template
  is resolved against the *app's* own `templates/`, not this bundle's.
- `templates/main/seo-check.html.twig` and `templates/sitemap.xml.twig` — same
  deal, app-supplied (the /seo-check *behavior* is core; its page is not)
- Two parameters: `app.admin_user`, `app.admin_pass` (HTTP Basic creds for
  `/admin`) — `AdminAuth` refuses to run on an empty value or `"changeme"`
- A Twig global named `site` bound to `Coruja\Cms\Site` (the
  panel templates and `AdminAuth`'s docs assume it exists)
- A `cache.app`-compatible `Psr\Cache\CacheItemPoolInterface` for
  `SeoCheckController`'s rate limiting (Symfony provides this by default)

## Wiring it into an app

1. `composer require nickraleigh/coruja`
2. `config/bundles.php`:
   ```php
   Coruja\CorujaBundle::class => ['all' => true],
   ```
3. `config/services.yaml` — autowire the bundle's classes the same way the
   app autowires its own `src/`:
   ```yaml
   Coruja\:
       resource: '../vendor/nickraleigh/coruja/src/'
       exclude: '../vendor/nickraleigh/coruja/src/CorujaBundle.php'
   ```
4. `config/routes.yaml` — import the bundle's attribute-routed controllers:
   ```yaml
   flat_file_cms:
       resource:
           path: '../vendor/nickraleigh/coruja/src/Controller/'
           namespace: Coruja\Controller
       type: attribute
   ```
5. Give every page template a `content/site.yaml` + a `site` Twig global, and
   set `app.admin_user` / `app.admin_pass` parameters from env vars.

## Contract points worth knowing before a second site relies on this

Carried over from the extraction audit (nr-symfony's `docs/CORE-EXTRACTION-AUDIT.md`):

- **The contact inbox is not part of this bundle.** `AdminController`/
  `admin/inbox.html.twig` stayed in nr-symfony because it currently only
  knows about that site's `ContactController` JSON shape
  (`{name, email, message, at, ip}` files in `var/contact/`). A second site
  wanting an inbox needs either its own, or this needs a small generic
  contract added here.
- **Tests are not yet split out.** nr-symfony's test suite still exercises
  this code through the full app (`tests/PanelTest.php`,
  `ContentTest.php`, `PageRenderTest.php`, `SeoAnalyzerTest.php`,
  `SeoCheckControllerTest.php`, `SiteCheckerTest.php`, `GitSyncTest.php`).
  This bundle has no test suite of its own yet — that's real follow-up work,
  not done as part of this extraction.
- **`/seo-check` ships gated off by default** (`seo_check: 'off'` in
  `content/site.yaml`, checked by `SeoCheckController::enabled()`). Whether
  that gate itself belongs in core, or is just nickraleigh.com's current
  caution, hasn't been decided.
