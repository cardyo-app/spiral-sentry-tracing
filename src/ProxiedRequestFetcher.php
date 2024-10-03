<?php

declare(strict_types=1);

namespace Cardyo\SpiralSentryTracing;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\Integration\RequestFetcherInterface;
use Spiral\Core\Attribute\Proxy;

final class ProxiedRequestFetcher implements RequestFetcherInterface
{
    public function __construct(
        #[Proxy]
        private readonly ContainerInterface $container,
    ) {
    }

    public function fetchRequest(): ?ServerRequestInterface
    {
        if (!$this->container->has(ServerRequestInterface::class)) {
            return null;
        }

        return $this->container->get(ServerRequestInterface::class);
    }
}
