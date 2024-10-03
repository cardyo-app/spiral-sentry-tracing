<?php

declare(strict_types=1);

namespace Cardyo\SpiralSentryTracing\Interceptor;

use Cardyo\SpiralSentryTracing\Attribute\Traced;
use ReflectionClass;
use ReflectionException;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Spiral\Attributes\ReaderInterface;
use Spiral\Core\CoreInterceptorInterface;
use Spiral\Core\CoreInterface;

final class SentryEventInterceptor implements CoreInterceptorInterface
{
    public function __construct(
        private readonly ReaderInterface $reader,
    ) {
    }

    public function process(string $controller, string $action, array $parameters, CoreInterface $core): mixed
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();
        $event = $parameters['event'];

        if ($parentSpan === null || $event === null || !$this->shouldBeTraced($event)) {
            return $core->callAction($controller, $action, $parameters);
        }

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('event')
                ->setDescription($event::class),
        );

        SentrySdk::getCurrentHub()->setSpan($span);

        try {
            return $core->callAction($controller, $action, $parameters);
        } finally {
            $span->finish();

            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
    }

    private function shouldBeTraced(object $event): bool
    {
        try {
            $class = new ReflectionClass($event);
        } catch (ReflectionException) {
            return false;
        }

        $attributed = $this->reader->firstClassMetadata($class, Traced::class);
        return $attributed !== null;
    }
}

