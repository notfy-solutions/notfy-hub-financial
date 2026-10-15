<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Contracts;

interface CheckoutGateway
{
    /**
     * @param  array<string, mixed>  $params
     * @return array{id: ?string, url: ?string, raw: array<string, mixed>}
     */
    public function createCheckoutSession(array $params): array;

    /**
     * @return array{url: ?string, raw: array<string, mixed>}
     */
    public function createBillingPortalSession(string $customerExternalId, string $returnUrl): array;
}
