<?php

namespace Cardyo\SpiralSentryTracing\Integration;

use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;
use Sentry\Tracing\TransactionSource;
use Spiral\Events\ListenerRegistryInterface;
use Spiral\Router\Event\RouteMatched;
use Spiral\Router\Router;

class RoutingIntegration implements IntegrationInterface
{
    public function __construct(
        protected readonly ListenerRegistryInterface $listenerRegistry
    ) {
    }

    public function setupOnce(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->listenerRegistry->addListener(RouteMatched::class, [$this, 'onRouteMatched']);
    }

    public function onRouteMatched(RouteMatched $event): void
    {
        $transaction = SentrySdk::getCurrentHub()->getTransaction();

        if ($transaction === null) {
            return;
        }

        $route = $event->route;

        $basePath = $route->getUriHandler()->getBasePath();
        $prefix = $route->getUriHandler()->getPrefix();
        $pattern = $route->getUriHandler()->getPattern();

        $template = $basePath . $prefix . $pattern;

        $transaction->setName(sprintf('%s %s', $event->request->getMethod(), $template));
        $transaction->getMetadata()->setSource(TransactionSource::route());

        $routeName = $event->request->getAttribute(Router::ROUTE_NAME);

        if ($routeName !== null) {
            $transaction->setData([
                ...$transaction->getData(),
                'route' => $routeName,
            ]);
        }
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
