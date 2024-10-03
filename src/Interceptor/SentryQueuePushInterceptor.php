<?php

declare(strict_types=1);

namespace Cardyo\SpiralSentryTracing\Interceptor;

use Ramsey\Uuid\Uuid;
use ReflectionClass;
use ReflectionException;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Spiral\Attributes\ReaderInterface;
use Spiral\Core\CoreInterceptorInterface;
use Spiral\Core\CoreInterface;
use Spiral\Queue\Attribute\JobHandler;
use Spiral\Queue\Options;
use function Sentry\getBaggage;
use function Sentry\getTraceparent;

final class SentryQueuePushInterceptor implements CoreInterceptorInterface
{
    public function __construct(
        private readonly ReaderInterface $reader,
    ) {
    }

    public function process(string $controller, string $action, array $parameters, CoreInterface $core): mixed
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();
        if ($parentSpan === null) {
            return $core->callAction($controller, $action, $parameters);
        }

        $sentryMessageId = Uuid::uuid7()->toString();
        $options = $parameters['options'] ?? new Options();

        $context = SpanContext::make()
            ->setOp('queue.publish')
            ->setData([
                'messaging.system' => 'spiral',
                'messaging.message.id' => $sentryMessageId,
                'messaging.destination.name' => $this->getQueueName($controller),
                'messaging.message.body.size' => strlen(json_encode($parameters['payload'] ?? [])),
            ])
            ->setDescription($this->getQueueName($controller));

        $span = $parentSpan->startChild($context);
        SentrySdk::getCurrentHub()->setSpan($span);

        $options = $options->withHeader('sentry_baggage_data', getBaggage());
        $options = $options->withHeader('sentry_trace_parent_data', getTraceparent());
        $options = $options->withHeader('sentry_publish_time', (string) microtime(true));
        $options = $options->withHeader('sentry_job_id', $sentryMessageId);

        $parameters['options'] = $options;

        try {
            return $core->callAction($controller, $action, $parameters);
        } finally {
            $span->finish();

            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
    }

    /**
     * @param string|class-string $name
     */
    private function getQueueName(string $name): string
    {
        if (!str_contains($name, '\\') || !class_exists($name)) {
            return $name;
        }

        try {
            $class = new ReflectionClass($name);
        } catch (ReflectionException) {
            return $name;
        }

        $handler = $this->reader->firstClassMetadata($class, JobHandler::class);
        if ($handler === null) {
            return $name;
        }

        return $handler->type;
    }
}
