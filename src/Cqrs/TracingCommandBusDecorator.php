<?php

declare(strict_types=1);

namespace Cardyo\SpiralSentryTracing\Cqrs;

use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Spiral\Cqrs\CommandBusInterface;
use Spiral\Cqrs\CommandInterface;

final class TracingCommandBusDecorator implements CommandBusInterface
{
    public function __construct(
        private readonly CommandBusInterface $bus,
    ) {
    }

    public function dispatch(CommandInterface $command): mixed
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();
        if ($parentSpan === null || $parentSpan->getSampled() === false) {
            return $this->bus->dispatch($command);
        }

        $span = $parentSpan->startChild(
            (new SpanContext())
                ->setOp('function.cqrs.command')
                ->setDescription($command::class),
        );

        SentrySdk::getCurrentHub()->setSpan($span);

        try {
            return $this->bus->dispatch($command);
        } finally {
            $span->finish();

            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
    }
}
