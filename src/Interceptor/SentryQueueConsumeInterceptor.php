<?php

declare(strict_types=1);

namespace Cardyo\SpiralSentryTracing\Interceptor;

use ReflectionClass;
use ReflectionException;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use Spiral\Attributes\ReaderInterface;
use Spiral\Core\CoreInterceptorInterface;
use Spiral\Core\CoreInterface;
use Spiral\Queue\Attribute\JobHandler;
use Throwable;
use function Sentry\continueTrace;

final class SentryQueueConsumeInterceptor implements CoreInterceptorInterface
{
    public function __construct(
        private readonly ReaderInterface $reader,
    ) {
    }

    public function process(string $controller, string $action, array $parameters, CoreInterface $core): mixed
    {
        $currentSpan = SentrySdk::getCurrentHub()->getSpan();

        $queueName = $this->getQueueName($controller);
        $headers = $parameters['headers'] ?? [];

        if ($currentSpan === null) {
            $sentryTrace = $headers['sentry_trace_parent_data'][0] ?? '';
            $baggage = $headers['sentry_baggage_data'][0] ?? '';

            $context = continueTrace($sentryTrace ?? '', $baggage ?? '');
        } else {
            $context = SpanContext::make();
        }

        $jobPublishedAt = ($headers['sentry_publish_time'][0] ?? null);
        $jobReceiveLatency = $jobPublishedAt !== null
            ? microtime(true) - (float)$jobPublishedAt
            : null;

        $sentryJobId = ($headers['sentry_job_id'][0] ?? null);
        $attempts = (int)($headers['attempts'][0] ?? 0);

        $jobData = [
            'messaging.system' => 'spiral',
            'messaging.destination.name' => $queueName,
            'messaging.message.id' => $sentryJobId,
            'messaging.message.receive.latency' => $jobReceiveLatency,
            'messaging.message.body.size' => strlen(json_encode($parameters['payload'] ?? [])),
            'messaging.message.retry.count' => $attempts,
        ];

        if ($context instanceof TransactionContext) {
            $context->setName($queueName);
            $context->setSource(TransactionSource::task());
        }

        $context->setOp('queue.process');
        $context->setDescription($queueName);
        $context->setData($jobData);
        $context->setOrigin('auto.queue');
        $context->setStartTimestamp(microtime(true));

        if ($currentSpan === null) {
            $span = SentrySdk::getCurrentHub()->startTransaction($context);
        } else {
            $span = $currentSpan->startChild($context);
        }

        SentrySdk::getCurrentHub()->setSpan($span);

        try {
            $result = $core->callAction($controller, $action, $parameters);

            $span->setStatus(SpanStatus::ok());

            return $result;
        } catch (Throwable $e) {
            $span->setStatus(SpanStatus::internalError());

            throw $e;
        } finally {
            $span->finish();

            SentrySdk::getCurrentHub()->setSpan($currentSpan);
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

    protected static function arrayGet(array $array, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        return $default;
    }

    protected static function arrayFirst(array $array, mixed $default = null): mixed
    {
        if (empty($array)) {
            return $default;
        }

        return reset($array);
    }
}
