<?php

declare(strict_types=1);

namespace Cardyo\SpiralSentryTracing\Cqrs;

use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Spiral\Cqrs\QueryBusInterface;
use Spiral\Cqrs\QueryInterface;

final class TracingQueryBusDecorator implements QueryBusInterface
{
    public function __construct(
        private readonly QueryBusInterface $bus,
    ) {
    }

    public function ask(QueryInterface $query): mixed
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();
        if ($parentSpan === null || $parentSpan->getSampled() === false) {
            return $this->bus->ask($query);
        }

        $span = $parentSpan->startChild(
            (new SpanContext())
                ->setOp('function.cqrs.query')
                ->setDescription($query::class),
        );

        SentrySdk::getCurrentHub()->setSpan($span);

        try {
            return $this->bus->ask($query);
        } finally {
            $span->finish();

            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
    }
}
