<?php

namespace Modules\Outbound\Services\Logistics;

use Modules\Outbound\Contracts\MarketPlaceLogisticsInterface;

class LogisticsGateway
{
    /**
     * @param  array<string,class-string<MarketPlaceLogisticsInterface>>  $adapters
     */
    public function __construct(private array $adapters = []) {}

    public function for(?string $source): MarketPlaceLogisticsInterface
    {
        $key = strtolower(trim((string) $source));
        $class = $this->adapters[$key] ?? $this->adapters['default'] ?? null;
        if (! $class) {
            throw new \RuntimeException("No logistics adapter registered for source '{$source}'.");
        }

        return app($class);
    }

    public function supports(?string $source): bool
    {
        $key = strtolower(trim((string) $source));
        return isset($this->adapters[$key]);
    }
}
