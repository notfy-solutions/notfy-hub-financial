<?php

declare(strict_types=1);

namespace Notfy\HubFinancial;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Notfy\HubFinancial\Contracts\GatewayPlugin;
use Notfy\HubFinancial\Contracts\ProvidesWebhookHandler;
use Notfy\HubFinancial\Resilience\CircuitBreaker;
use Notfy\HubFinancial\Resilience\ResilientGatewayFactory;
use Notfy\HubFinancial\Resilience\RetryPolicy;
use Notfy\HubFinancial\Support\GatewayManager;
use Notfy\HubFinancial\Support\GatewayResolver;
use Notfy\HubFinancial\Support\HubFinancialConfig;
use Notfy\HubFinancial\Webhooks\WebhookRouter;

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

        $this->app->singleton(GatewayManager::class, function () use ($plugins): GatewayManager {
            $manager = new GatewayManager;

            foreach ($plugins as $plugin) {
                $manager->extend($plugin->name(), $plugin->build(...));
            }

            return $manager;
        });

        $this->app->singleton(WebhookRouter::class, function (Container $app) use ($plugins): WebhookRouter {
            $config = $app->make(HubFinancialConfig::class);
            $router = new WebhookRouter(
                $app->make(CacheFactory::class)->store(),
                $app->make(Dispatcher::class),
                $config->webhookDedupeTtl(),
            );

            foreach ($plugins as $plugin) {
                if ($plugin instanceof ProvidesWebhookHandler) {
                    $router->register($plugin->name(), $plugin->webhookHandler());
                }
            }

            return $router;
        });

        $this->app->singleton(ResilientGatewayFactory::class, function (Container $app): ResilientGatewayFactory {
            $resilience = $app->make(HubFinancialConfig::class)->resilience();

            return new ResilientGatewayFactory(
                new RetryPolicy($resilience['max_attempts'], $resilience['base_delay_ms'], $resilience['max_delay_ms']),
                new CircuitBreaker(
                    $app->make(CacheFactory::class)->store(),
                    $resilience['failure_threshold'],
                    $resilience['cooldown_seconds'],
                ),
                $app->make(Dispatcher::class),
            );
        });

        $this->app->singleton(GatewayResolver::class, fn (Container $app): GatewayResolver => new GatewayResolver(
            $app->make(GatewayManager::class),
            $app->make(HubFinancialConfig::class)->routing(),
        ));
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
