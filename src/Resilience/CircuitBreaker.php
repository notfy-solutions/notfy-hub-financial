<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Resilience;

use Illuminate\Contracts\Cache\Repository;

/** Circuit breaker por gateway, com estado no cache (compartilhado entre workers). */
final readonly class CircuitBreaker
{
    public function __construct(
        private Repository $cache,
        private int $failureThreshold = 5,
        private int $cooldownSeconds = 30,
    ) {}

    public function isOpen(string $gateway): bool
    {
        return $this->cache->has($this->openKey($gateway));
    }

    public function recordSuccess(string $gateway): void
    {
        $this->cache->forget($this->failuresKey($gateway));
    }

    /** @return bool true se esta falha abriu o circuito */
    public function recordFailure(string $gateway): bool
    {
        $key = $this->failuresKey($gateway);
        $this->cache->add($key, 0, $this->cooldownSeconds * 2);
        $failures = (int) $this->cache->increment($key);

        if ($failures < $this->failureThreshold) {
            return false;
        }

        $this->cache->put($this->openKey($gateway), true, $this->cooldownSeconds);
        $this->cache->forget($key);

        return true;
    }

    private function openKey(string $gateway): string
    {
        return "hub-financial:circuit:{$gateway}:open";
    }

    private function failuresKey(string $gateway): string
    {
        return "hub-financial:circuit:{$gateway}:failures";
    }
}
