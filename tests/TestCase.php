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
        $app['config']->set('hub-financial.gateways.asaas.base_url', 'https://api-sandbox.asaas.com/v3');
    }
}
