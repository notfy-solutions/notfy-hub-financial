<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

use Illuminate\Contracts\Config\Repository;
use Notfy\HubFinancial\Contracts\BillingGateway;
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
     * @return array<string, array{class: class-string<BillingGateway>}>
     */
    public function gateways(): array
    {
        $gateways = [];

        foreach (ApiPayload::toAssocArray($this->config->get('hub-financial.gateways', [])) as $name => $options) {
            $class = ApiPayload::toAssocArray($options)['class'] ?? null;

            if (is_string($class) && is_a($class, BillingGateway::class, true)) {
                $gateways[$name] = ['class' => $class];
            }
        }

        return $gateways;
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

    public function gatewayOption(string $gateway, string $key): string
    {
        $options = ApiPayload::toAssocArray($this->config->get("hub-financial.gateways.{$gateway}", []));

        return ApiPayload::stringOrDefault($options[$key] ?? null, '');
    }
}
