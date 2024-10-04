<?php

declare(strict_types=1);

namespace Cardyo\SpiralSentryTracing;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionSource;
use Spiral\Core\ScopeInterface;
use Throwable;
use function Sentry\continueTrace;

class Middleware implements MiddlewareInterface
{
    private ?Transaction $transaction = null;

    private ?Span $appSpan = null;

    public function __construct(
        private readonly ?HubInterface $sentry,
        private readonly ScopeInterface $container,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->sentry !== null) {
            $this->startTransaction($request);
        }

        try {
            $response = $handler->handle($request);

            $this->hydrateResponseData($response);

            return $response;
        } catch (Throwable $e) {
            $this->transaction?->setStatus(SpanStatus::internalError());
            throw $e;
        } finally {
            if ($this->appSpan !== null) {
                $this->appSpan->finish();
                $this->appSpan = null;
            }

            $this->finishTransaction();
        }
    }

    private function startTransaction(ServerRequestInterface $request): void
    {
        $this->container->bindSingleton(ServerRequestInterface::class, $request);

        // Prevent starting a new transaction if we are already in a transaction
        if ($this->sentry === null || $this->sentry->getTransaction() !== null) {
            return;
        }

        $requestStartTime = $request->getServerParams()['REQUEST_TIME_FLOAT'] ?? microtime(true);

        $context = continueTrace(
            $request->getHeaderLine('sentry-trace') ?: $request->getHeaderLine('traceparent'),
            $request->getHeaderLine('baggage'),
        );

        $context->setOp('http.server');
        $context->setOrigin('auto.http.server');

        $requestPath = '/' . ltrim($request->getUri()->getPath(), '/');

        $context->setName(sprintf('%s %s', $request->getMethod(), $requestPath));
        $context->setSource(TransactionSource::url());

        $context->setStartTimestamp($requestStartTime);
        $context->setData([
            'net.host.port' => $request->getUri()->getPort(),
            'http.url' => $requestPath,
            'http.method' => strtoupper($request->getMethod()),
            'http.request.method' => strtoupper($request->getMethod()),
        ]);

        $transaction = $this->sentry->startTransaction($context);

        SentrySdk::getCurrentHub()->setSpan($transaction);

        // If this transaction is not sampled, we can stop here to prevent doing work for nothing
        if (!$transaction->getSampled()) {
            return;
        }

        $this->transaction = $transaction;

        $this->appSpan = $this->transaction->startChild(
            SpanContext::make()
                ->setOp('middleware.handle')
                ->setOrigin('auto.http.server')
                ->setStartTimestamp(microtime(true)),
        );

        SentrySdk::getCurrentHub()->setSpan($this->appSpan);
    }

    private function hydrateResponseData(ResponseInterface $response): void
    {
        if ($this->transaction === null) {
            return;
        }

        $this->transaction->setHttpStatus($response->getStatusCode());

        $this->transaction->setData([
            ...$this->transaction->getData(),
            'http.status_code' => $response->getStatusCode(),
        ]);
    }

    private function finishTransaction(): void
    {
        if ($this->transaction === null) {
            return;
        }

        SentrySdk::getCurrentHub()->setSpan($this->transaction);

        $this->transaction->finish();
        $this->transaction = null;

        $this->container->removeBinding(ServerRequestInterface::class);
    }
}
