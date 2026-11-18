<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Feed;

/**
 * Collects all registered feed providers (tagged services) and makes them
 * retrievable by their resource name.
 */
final class FeedProviderRegistry
{
    /** @var array<string, FeedProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<FeedProviderInterface> $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->getResource()] = $provider;
        }
    }

    public function has(string $resource): bool
    {
        return isset($this->providers[$resource]);
    }

    public function get(string $resource): ?FeedProviderInterface
    {
        return $this->providers[$resource] ?? null;
    }
}
