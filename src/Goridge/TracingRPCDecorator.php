<?php

declare(strict_types=1);

namespace Cardyo\SpiralSentryTracing\Goridge;

use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Spiral\Goridge\RPC\CodecInterface;
use Spiral\Goridge\RPC\RPCInterface;

final class TracingRPCDecorator implements RPCInterface
{
    public function __construct(
        private readonly RPCInterface $rpc,
    ) {
    }

    public function withServicePrefix(string $service): self
    {
        return new self($this->rpc->withServicePrefix($service));
    }

    public function withCodec(CodecInterface $codec): self
    {
        return new self($this->rpc->withCodec($codec));
    }

    public function call(string $method, mixed $payload, mixed $options = null): mixed
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();
        if ($parentSpan === null || $parentSpan->getSampled() === false) {
            return $this->rpc->call($method, $payload, $options);
        }

        $context = SpanContext::make()
            ->setOp('grpc.client')
            ->setData([
                'grpc.request.method' => $method,
            ])
            ->setDescription($method);

        $span = $parentSpan->startChild($context);
        SentrySdk::getCurrentHub()->setSpan($span);

        try {
            return $this->rpc->call($method, $payload, $options);
        } finally {
            $span->finish();

            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
    }
}
