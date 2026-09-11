<?php

namespace Coruja\Cms;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * The one place the panel's HTTP Basic credentials are checked.
 *
 * Used by {@see \Coruja\EventListener\AdminAuthListener} to gate /admin, and by
 * {@see \Coruja\Controller\PageController} to let an authenticated editor preview
 * draft pages on the public site.
 */
final class AdminAuth
{
    public function __construct(
        #[Autowire('%app.admin_user%')]
        private readonly string $expectedUser,
        #[Autowire('%app.admin_pass%')]
        private readonly string $expectedPass,
    ) {
    }

    public function check(Request $request): bool
    {
        // Refuse to run with an unset / placeholder password.
        if ('' === $this->expectedPass || 'changeme' === $this->expectedPass) {
            return false;
        }

        return hash_equals($this->expectedUser, (string) $request->getUser())
            && hash_equals($this->expectedPass, (string) $request->getPassword());
    }
}
