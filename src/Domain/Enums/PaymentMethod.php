<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Domain\Enums;

enum PaymentMethod: string
{
    case Boleto = 'BOLETO';
    case Pix = 'PIX';
    case Card = 'CREDIT_CARD';
    case Hybrid = 'BOLETO_PIX';
    case Undefined = 'UNDEFINED';
}
