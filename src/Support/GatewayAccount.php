<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

use Notfy\HubFinancial\Credentials\GatewayCredentials;

/**
 * Envelope agnóstico de uma conta de gateway. Credenciais podem vir de
 * env/config, do banco (por tenant) ou ser passadas diretamente (BYO key) —
 * o pacote recebe o objeto pronto; quem decide a fonte é a aplicação.
 */
final class GatewayAccount
{
    /**
     * @param  array<string, mixed>  $options  Config de infraestrutura (ex.: base_url, default_currency).
     */
    public function __construct(
        public readonly string $provider,
        public readonly GatewayCredentials $credentials,
        public readonly ?string $webhookSecret = null,
        public readonly array $options = [],
    ) {}
}
