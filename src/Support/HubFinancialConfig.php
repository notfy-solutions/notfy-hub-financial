<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

use Illuminate\Contracts\Config\Repository;
use Notfy\HubFinancial\Contracts\GatewayPlugin;

/**
 * Leitura tipada de `config/hub-financial.php`. Concentra a validação do
 * formato para que ServiceProvider e plugins não espalhem casts sobre `mixed`.
 */
final readonly class HubFinancialConfig
{
    public function __construct(private Repository $config) {}

    /**
     * @return list<class-string<GatewayPlugin>>
     */
    public function plugins(): array
    {
        $plugins = [];

        foreach (ApiPayload::toAssocArray($this->config->get('hub-financial.plugins', [])) as $class) {
            if (is_string($class) && is_a($class, GatewayPlugin::class, true)) {
                $plugins[] = $class;
            }
        }

        return $plugins;
    }

    /**
     * @return array<string, array<string, string>> país => (tipo => nome do gateway)
     */
    public function routing(): array
    {
        $routing = [];

        foreach (ApiPayload::toAssocArray($this->config->get('hub-financial.routing', [])) as $country => $types) {
            foreach (ApiPayload::toAssocArray($types) as $type => $gateway) {
                if (is_string($gateway)) {
                    $routing[$country][$type] = $gateway;
                }
            }
        }

        return $routing;
    }

    public function webhookDedupeTtl(): int
    {
        return max(60, ApiPayload::toInt($this->config->get('hub-financial.webhooks.dedupe_ttl', 604800)));
    }

    /**
     * @return array{max_attempts: int, base_delay_ms: int, max_delay_ms: int, failure_threshold: int, cooldown_seconds: int}
     */
    public function resilience(): array
    {
        $get = fn (string $key, int $default): int => max(1, ApiPayload::toInt(
            $this->config->get("hub-financial.resilience.{$key}", $default),
        ));

        return [
            'max_attempts' => $get('max_attempts', 3),
            'base_delay_ms' => $get('base_delay_ms', 200),
            'max_delay_ms' => $get('max_delay_ms', 5000),
            'failure_threshold' => $get('failure_threshold', 5),
            'cooldown_seconds' => $get('cooldown_seconds', 30),
        ];
    }
}
