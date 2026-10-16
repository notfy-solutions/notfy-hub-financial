<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Contracts;

/** Plugin que expõe o handler de webhook do seu provedor ao WebhookRouter. */
interface ProvidesWebhookHandler
{
    public function webhookHandler(): WebhookHandler;
}
