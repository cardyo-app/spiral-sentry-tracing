<?php

namespace Cardyo\SpiralSentryTracing\Bootloader;

use Cardyo\SpiralSentryTracing\BacktraceHelper;
use Cardyo\SpiralSentryTracing\Cqrs\TracingCommandBusDecorator;
use Cardyo\SpiralSentryTracing\Goridge\TracingRPCDecorator;
use Cardyo\SpiralSentryTracing\ProxiedRequestFetcher;
use Sentry\Integration\RequestFetcherInterface;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\Serializer\RepresentationSerializerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Core\Container;
use Spiral\Cqrs\CommandBusInterface;
use Spiral\Cqrs\QueryBusInterface;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\Sentry\Bootloader\ClientBootloader;
use Spiral\League\Event\Bootloader\EventBootloader;

class SentryTracingBootloader extends Bootloader
{
    public function defineDependencies(): array
    {
        $dependencies = [
            ClientBootloader::class,
        ];

        if (class_exists(EventBootloader::class)) {
            $dependencies[] = EventBootloader::class;
        }

        return $dependencies;
    }

    public function defineSingletons(): array
    {
        $singletons = [
            RepresentationSerializerInterface::class => RepresentationSerializer::class,
            BacktraceHelper::class => BacktraceHelper::class,
            RequestFetcherInterface::class => ProxiedRequestFetcher::class,
        ];

        return $singletons;
    }

    public function init(Container $container): void
    {
        if (class_exists(CommandBusInterface::class) && $container->has(CommandBusInterface::class)) {
            $container->bindSingleton(
                CommandBusInterface::class,
                new TracingCommandBusDecorator($container->get(CommandBusInterface::class)),
            );
        }

        if (class_exists(QueryBusInterface::class) && $container->has(QueryBusInterface::class)) {
            $container->bindSingleton(
                QueryBusInterface::class,
                new TracingCommandBusDecorator($container->get(QueryBusInterface::class)),
            );
        }

        if (class_exists(RPCInterface::class) && $container->has(RPCInterface::class)) {
            $container->bindSingleton(
                RPCInterface::class,
                new TracingRPCDecorator($container->get(RPCInterface::class)),
            );
        }
    }
}
