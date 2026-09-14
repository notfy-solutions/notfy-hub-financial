<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Webhooks;

final class StripeWebhookEvent
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public readonly string $type,               // ex: payment_intent.succeeded
        public readonly ?string $objectId,
        public readonly ?string $externalReference,
        public readonly ?string $status,
        public readonly array $rawPayload,
    ) {}

    public function isPaymentEvent(): bool
    {
        return str_starts_with($this->type, 'payment_intent.');
    }

    public function isRefundEvent(): bool
    {
        return str_starts_with($this->type, 'charge.refund');
    }
}
