<?php

namespace Coruja;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The flat-file CMS core: content repository, blueprint-driven forms, the
 * admin panel, on-page SEO analysis, and the SSRF-guarded URL checker.
 *
 * A consuming app registers this bundle in config/bundles.php, then wires it
 * the same way it wires its own src/ (see README.md):
 *   - config/services.yaml: autowire Coruja\ from vendor
 *   - config/routes.yaml:   import its Controller/ directory as attributes
 *   - content/, config/blueprints/*.yaml, templates/main/*, scss/ all stay
 *     in the app — this bundle only ships the parts that don't vary by site.
 *
 * Panel templates are namespaced @Coruja (Symfony derives this from the
 * bundle's short name) and live at src/templates/panel/, which is where
 * TwigBundle looks for a bundle's templates by convention.
 */
class CorujaBundle extends Bundle
{
}
