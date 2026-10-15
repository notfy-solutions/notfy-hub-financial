<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Webhooks;

use Notfy\HubFinancial\Domain\Enums\PaymentEventType;

/**
 * Evento de pagamento normalizado e agnóstico de provedor.
 * Todos os webhooks de qualquer driver resultam neste objeto.
 */
final class PaymentEvent
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly PaymentEventType $type,
        public readonly string $chargeId,
        public readonly ?string $externalReference,
        public readonly ?int $amountPaidCents,
        public readonly ?int $feeCents,
        public readonly ?string $paidAt,
        public readonly string $provider,
        public readonly string $eventId,
        public readonly array $raw,
    ) {}
}
