<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Contracts;

/**
 * Composição dos contratos segregados de cobrança. Todo BillingGateway se
 * descreve (DescribesGateway); drivers que cobrem só uma parte (ex.: só
 * extrato bancário) implementam apenas os contratos que suportam.
 */
interface BillingGateway extends ChargeGateway, CheckoutGateway, CustomerGateway, DescribesGateway {}
