<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Contracts;

use Notfy\HubFinancial\Domain\Enums\Capability;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;

/**
 * Todo gateway declara o que faz e onde opera. O resolvedor usa isso para
 * recusar, com mensagem clara, um pedido que o gateway não atende (país,
 * moeda, meio de pagamento ou capacidade), em vez de falhar no provedor.
 */
interface DescribesGateway
{
    /**
     * @return list<Capability>
     */
    public function capabilities(): array;

    /**
     * Códigos ISO 3166-1 alfa-2; '*' atende qualquer país.
     *
     * @return list<string>
     */
    public function supportedCountries(): array;

    /**
     * Códigos ISO 4217 em maiúsculas; '*' aceita qualquer moeda.
     *
     * @return list<string>
     */
    public function supportedCurrencies(): array;

    /**
     * @return list<PaymentMethod>
     */
    public function supportedPaymentMethods(): array;
}
