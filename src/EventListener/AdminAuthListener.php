<?php

namespace Coruja\EventListener;

use Coruja\Cms\AdminAuth;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * HTTP Basic auth for everything under /admin — the panel and the inbox.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
final class AdminAuthListener
{
    public function __construct(
        private readonly AdminAuth $auth,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/admin')) {
            return;
        }

        if ($this->auth->check($request)) {
            return;
        }

        $event->setResponse(new Response('Authentication required.', Response::HTTP_UNAUTHORIZED, [
            'WWW-Authenticate' => 'Basic realm="Nick Raleigh"',
            'Cache-Control' => 'no-store',
        ]));
    }
}
