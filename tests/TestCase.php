<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Tests;

use Notfy\HubFinancial\HubFinancialServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [HubFinancialServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('hub-financial.gateways.asaas.api_key', 'test-key');
        $app['config']->set('hub-financial.gateways.asaas.environment', 'sandbox');
        $app['config']->set('hub-financial.gateways.stripe.secret_key', 'sk_test_123');
        $app['config']->set('hub-financial.gateways.stripe.webhook_secret', 'whsec_test_123');
    }
}
