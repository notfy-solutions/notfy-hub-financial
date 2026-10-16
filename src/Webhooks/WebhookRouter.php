<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Webhooks;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Notfy\HubFinancial\Contracts\WebhookHandler;
use Notfy\HubFinancial\Events\PaymentEventReceived;
use Notfy\HubFinancial\Exceptions\InvalidWebhookException;
use Notfy\HubFinancial\Support\ApiPayload;
use Notfy\HubFinancial\Support\GatewayAccount;

/**
 * Ponto único de entrada de webhooks: provedor -> verificação -> normalização
 * -> deduplicação por eventId. Um país/provedor novo só registra seu handler.
 */
final class WebhookRouter
{
    /** @var array<string, WebhookHandler> */
    private array $handlers = [];

    public function __construct(
        private readonly Repository $cache,
        private readonly ?Dispatcher $events = null,
        private readonly int $dedupeTtlSeconds = 604800,
    ) {}

    public function register(string $provider, WebhookHandler $handler): void
    {
        $this->handlers[$provider] = $handler;
    }

    /**
     * @param  array<string, string>  $headers
     * @return PaymentEvent|null null quando o evento já foi processado (duplicado)
     *
     * @throws InvalidWebhookException
     */
    public function handle(string $provider, string $rawBody, array $headers, GatewayAccount $account): ?PaymentEvent
    {
        $handler = $this->handlers[$provider] ?? throw InvalidWebhookException::unknownProvider($provider);

        if (! $handler->verify($rawBody, $headers, $account)) {
            throw InvalidWebhookException::invalidSignature($provider);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            throw InvalidWebhookException::malformedBody($provider);
        }

        $event = $handler->normalize(ApiPayload::toAssocArray($payload));

        if ($event->eventId !== '' && ! $this->cache->add($this->key($event), true, $this->dedupeTtlSeconds)) {
            return null;
        }

        $this->events?->dispatch(new PaymentEventReceived($event));

        return $event;
    }

    /** Libera o evento para reentrega do provedor (use se o processamento da aplicação falhar). */
    public function release(PaymentEvent $event): void
    {
        $this->cache->forget($this->key($event));
    }

    private function key(PaymentEvent $event): string
    {
        return "hub-financial:webhook:{$event->provider}:{$event->eventId}";
    }
}
