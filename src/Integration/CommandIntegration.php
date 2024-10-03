<?php

namespace Cardyo\SpiralSentryTracing\Integration;

use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use Spiral\Console\Command;
use Spiral\Console\Event\CommandFinished;
use Spiral\Console\Event\CommandStarting;
use Spiral\Events\ListenerRegistryInterface;

class CommandIntegration implements IntegrationInterface
{
    public const OP_COMMAND = 'console.command';

    public function __construct(
        protected readonly ListenerRegistryInterface $listenerRegistry
    ) {
    }

    public function setupOnce(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->listenerRegistry->addListener(CommandStarting::class, [$this, 'onCommandStarting']);
        $this->listenerRegistry->addListener(CommandFinished::class, [$this, 'onCommandFinished']);
    }

    public function onCommandStarting(CommandStarting $event): void
    {
        $command = $event->command;
        $currentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($currentSpan === null) {
            $transactionContext = TransactionContext::make()
                ->setOp(self::OP_COMMAND)
                ->setOrigin('auto.console')
                ->setName($this->getCommandSpanName($command))
                ->setSource(TransactionSource::task());

            $span = SentrySdk::getCurrentHub()->startTransaction($transactionContext);
        } else {
            $spanContext = SpanContext::make()
                ->setOp(self::OP_COMMAND)
                ->setOrigin('auto.console')
                ->setDescription($this->getCommandSpanName($command));

            $span = $currentSpan->startChild($spanContext);
        }

        SentrySdk::getCurrentHub()->setSpan($span);
    }

    public function onCommandFinished(CommandFinished $event): void
    {
        $span = SentrySdk::getCurrentHub()->getSpan();

        if ($span === null) {
            return;
        }

        $span->finish();
    }

    private function getCommandSpanName(?Command $command): string
    {
        return $command?->getName() ?? '<unnamed command>';
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
