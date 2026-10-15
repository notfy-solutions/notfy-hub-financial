<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Contracts;

/**
 * Composição dos contratos segregados para compatibilidade com código existente.
 * Novos drivers podem implementar só os contratos que suportam (ChargeGateway,
 * CustomerGateway etc.) sem precisar do BillingGateway completo.
 */
interface BillingGateway extends ChargeGateway, CheckoutGateway, CustomerGateway {}
