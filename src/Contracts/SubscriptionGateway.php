<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Contracts;

interface SubscriptionGateway
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createSubscription(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateSubscription(string $externalId, array $payload): array;

    /**
     * @return array<string, mixed>|null
     */
    public function fetchSubscription(string $externalId): ?array;

    public function cancelSubscription(string $externalId): bool;
}
