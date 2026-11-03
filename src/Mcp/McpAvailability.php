<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Whether this installation offers the MCP endpoint and the pages for its tokens.
 *
 * Routes and firewalls are compiled into the container, so the setting is applied per request. This
 * runs after the router and before the firewalls, which gives the same 404 as a route that does not
 * exist, for everybody.
 */
final readonly class McpAvailability
{
    private const array ROUTE_PREFIXES = ['_mcp_endpoint_', 'admin_api_token_'];

    public function __construct(
        #[Autowire('%env(bool:MCP_ENABLED)%')]
        private bool $enabled,
        private RouterInterface $router,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
    public function rejectRoutesWhenDisabled(RequestEvent $event): void
    {
        if (!$this->enabled && $this->isHidden((string) $event->getRequest()->attributes->get('_route'))) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * For a method the route does not take, the router already gave up, so the listener above never
     * sees a route. Only then, this matches again with a method the route does take to find out which
     * one it was. Runs before the error is logged and rendered.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 16)]
    public function hideMethodNotAllowedWhenDisabled(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($this->enabled || !$exception instanceof MethodNotAllowedHttpException) {
            return;
        }

        $context = $this->router->getContext();
        $method = $context->getMethod();
        $context->setMethod((string) strtok((string) $exception->getHeaders()['Allow'], ', '));

        try {
            $route = (string) ($this->router->match($event->getRequest()->getPathInfo())['_route'] ?? '');
        } catch (RoutingException) {
            return;
        } finally {
            $context->setMethod($method);
        }

        if ($this->isHidden($route)) {
            $event->setThrowable(new NotFoundHttpException());
        }
    }

    private function isHidden(string $route): bool
    {
        foreach (self::ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
