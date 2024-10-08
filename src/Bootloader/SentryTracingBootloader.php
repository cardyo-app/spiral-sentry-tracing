<?php

namespace Cardyo\SpiralSentryTracing\Bootloader;

use Cardyo\SpiralSentryTracing\BacktraceHelper;
use Cardyo\SpiralSentryTracing\Cqrs\TracingCommandBusDecorator;
use Cardyo\SpiralSentryTracing\Cqrs\TracingQueryBusDecorator;
use Cardyo\SpiralSentryTracing\Goridge\TracingRPCDecorator;
use Cardyo\SpiralSentryTracing\Integration\CommandIntegration;
use Cardyo\SpiralSentryTracing\Integration\RoutingIntegration;
use Cardyo\SpiralSentryTracing\ProxiedRequestFetcher;
use Sentry\Client;
use Sentry\Integration\RequestFetcherInterface;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\Serializer\RepresentationSerializerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Boot\FinalizerInterface;
use Spiral\Core\Container;
use Spiral\Cqrs\CommandBusInterface;
use Spiral\Cqrs\QueryBusInterface;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\League\Event\Bootloader\EventBootloader;
use Spiral\Sentry\Bootloader\ClientBootloader;

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
        return [
            RepresentationSerializerInterface::class => RepresentationSerializer::class,
            BacktraceHelper::class => BacktraceHelper::class,
            RequestFetcherInterface::class => ProxiedRequestFetcher::class,

            RoutingIntegration::class => RoutingIntegration::class,
            CommandIntegration::class => CommandIntegration::class,
        ];
    }

    public function init(Container $container, FinalizerInterface $finalizer): void
    {
        // CQRS
        if (interface_exists(CommandBusInterface::class) && $container->has(CommandBusInterface::class)) {
            $container->bindSingleton(
                CommandBusInterface::class,
                new TracingCommandBusDecorator($container->get(CommandBusInterface::class)),
            );
        }
        if (interface_exists(QueryBusInterface::class) && $container->has(QueryBusInterface::class)) {
            $container->bindSingleton(
                QueryBusInterface::class,
                new TracingQueryBusDecorator($container->get(QueryBusInterface::class)),
            );
        }

        // RoadRunner Bridge
        if (interface_exists(RPCInterface::class) && $container->has(RPCInterface::class)) {
            $container->bindSingleton(
                RPCInterface::class,
                new TracingRPCDecorator($container->get(RPCInterface::class)),
            );
        }

        $finalizer->addFinalizer(
            static function (bool $terminate) use ($container): void {
                if ($terminate || !$container->hasInstance(Client::class)) {
                    return;
                }

                $container->get(Client::class)->flush();
            },
        );
    }
}
