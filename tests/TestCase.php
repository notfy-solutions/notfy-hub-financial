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
}
