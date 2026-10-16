<?php

declare(strict_types=1);

namespace Notfy\HubFinancial;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Contracts\GatewayPlugin;
use Notfy\HubFinancial\Support\DefaultGatewayResolver;
use Notfy\HubFinancial\Support\GatewayManager;
use Notfy\HubFinancial\Support\GatewayResolver;
use Notfy\HubFinancial\Support\HubFinancialConfig;

/**
 * Apenas orquestra: cada provedor se registra sozinho por um GatewayPlugin
 * listado em `hub-financial.plugins`.
 */
final class HubFinancialServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hub-financial.php', 'hub-financial');

        $this->app->bind(HubFinancialConfig::class, fn (Container $app): HubFinancialConfig => new HubFinancialConfig($app->make(ConfigRepository::class)));

        $plugins = $this->plugins();

        foreach ($plugins as $plugin) {
            $plugin->register();
        }

        $this->app->singleton(GatewayManager::class, function () use ($plugins): GatewayManager {
            $manager = new GatewayManager;

            foreach ($plugins as $plugin) {
                $manager->extend($plugin->name(), $plugin->build(...));
            }

            return $manager;
        });

        $this->app->singleton(GatewayResolver::class, fn (Container $app): GatewayResolver => new DefaultGatewayResolver(
            gateways: $app->make(HubFinancialConfig::class)->gateways(),
            routing: $app->make(HubFinancialConfig::class)->routing(),
        ));

        $this->app->singleton(
            BillingGateway::class,
            fn (Container $app): BillingGateway => $app->make(GatewayResolver::class)->resolve(),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/hub-financial.php' => config_path('hub-financial.php'),
            ], 'hub-financial-config');
        }
    }

    /**
     * @return list<GatewayPlugin>
     */
    private function plugins(): array
    {
        return array_map(
            fn (string $class): GatewayPlugin => $this->app->make($class),
            $this->app->make(HubFinancialConfig::class)->plugins(),
        );
    }
}
