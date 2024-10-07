<?php

namespace Cardyo\SpiralSentryTracing\Integration;

use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionSource;
use Spiral\Events\ListenerRegistryInterface;
use Spiral\Router\Event\RouteMatched;
use Spiral\Router\Event\RouteNotFound;
use Spiral\Router\Event\Routing;
use Spiral\Router\Router;

class RoutingIntegration implements IntegrationInterface
{
    private ?Span $parentSpan = null;
    private ?Span $span = null;

    public function __construct(
        protected readonly ListenerRegistryInterface $listenerRegistry
    ) {
    }

    public function setupOnce(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->listenerRegistry->addListener(Routing::class, [$this, 'onRouting']);
        $this->listenerRegistry->addListener(RouteMatched::class, [$this, 'onRouteMatched']);
        $this->listenerRegistry->addListener(RouteNotFound::class, [$this, 'onRouteNotFound']);
    }

    public function onRouting(Routing $event): void
    {
        $this->parentSpan = SentrySdk::getCurrentHub()->getSpan();
        if ($this->parentSpan === null) {
            return;
        }

        $this->span = $this->parentSpan->startChild(SpanContext::make()
            ->setOp('routing')
            ->setDescription($event->request->getUri()->getPath())
        );

        SentrySdk::getCurrentHub()->setSpan($this->span);
    }

    public function onRouteMatched(RouteMatched $event): void
    {
        $this->updateTransactionName($event);
        $this->finishRoutingSpan();
    }

    protected function updateTransactionName(RouteMatched $event): void
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

    public function onRouteNotFound(RouteNotFound $event): void
    {
        $this->finishRoutingSpan();
    }

    protected function finishRoutingSpan(): void
    {
        if ($this->span === null) {
            return;
        }

        $this->span->finish();
        $this->span = null;

        SentrySdk::getCurrentHub()->setSpan($this->parentSpan);
        $this->parentSpan = null;
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
