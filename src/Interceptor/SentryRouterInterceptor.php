<?php

declare(strict_types=1);

namespace Cardyo\SpiralSentryTracing\Interceptor;

use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Spiral\Core\CoreInterceptorInterface;
use Spiral\Core\CoreInterface;

final class SentryRouterInterceptor implements CoreInterceptorInterface
{
    public function process(string $controller, string $action, array $parameters, CoreInterface $core): mixed
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();
        if ($parentSpan === null || $parentSpan->getSampled() === false) {
            return $core->callAction($controller, $action, $parameters);
        }

        $span = $parentSpan->startChild(
            (new SpanContext())
                ->setOp('http.route')
                ->setDescription($controller . '@' . $action),
        );

        SentrySdk::getCurrentHub()->setSpan($span);

        try {
            return $core->callAction($controller, $action, $parameters);
        } finally {
            $span->finish();

            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
    }
}
