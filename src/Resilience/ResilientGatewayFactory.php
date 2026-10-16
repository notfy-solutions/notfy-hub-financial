<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Resilience;

use Illuminate\Contracts\Events\Dispatcher;
use Notfy\HubFinancial\Contracts\BillingGateway;

final readonly class ResilientGatewayFactory
{
    public function __construct(
        private RetryPolicy $retry,
        private CircuitBreaker $breaker,
        private ?Dispatcher $events = null,
    ) {}

    /**
     * @param  string  $name  Chave do circuito. Use um valor por conta (ex.: "stripe:tenant-42")
     *                        para que a falha de um tenant não abra o circuito dos demais.
     */
    public function wrap(BillingGateway $gateway, string $name): BillingGateway
    {
        return new ResilientGateway($gateway, $name, $this->retry, $this->breaker, $this->events);
    }
}
