<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Events\Dispatcher;
use Notfy\HubFinancial\Contracts\WebhookHandler;
use Notfy\HubFinancial\Credentials\ApiKey;
use Notfy\HubFinancial\Domain\Enums\PaymentEventType;
use Notfy\HubFinancial\Events\PaymentEventReceived;
use Notfy\HubFinancial\Exceptions\InvalidWebhookException;
use Notfy\HubFinancial\Resilience\ResilientGatewayFactory;
use Notfy\HubFinancial\Support\GatewayAccount;
use Notfy\HubFinancial\Support\HubFinancialConfig;
use Notfy\HubFinancial\Webhooks\PaymentEvent;
use Notfy\HubFinancial\Webhooks\WebhookRouter;

function webhookAccount(): GatewayAccount
{
    return new GatewayAccount('fake', new ApiKey('k'), 'secret');
}

function routerWith(?WebhookHandler $handler, array &$received = []): WebhookRouter
{
    $events = new Dispatcher;
    $events->listen(PaymentEventReceived::class, function ($e) use (&$received) {
        $received[] = $e;
    });
    $router = new WebhookRouter(new Repository(new ArrayStore), $events);

    if ($handler !== null) {
        $router->register('fake', $handler);
    }

    return $router;
}

it('rejects unknown providers', function () {
    routerWith(null)->handle('nope', '{}', [], webhookAccount());
})->throws(InvalidWebhookException::class);

it('rejects bodies that are not JSON objects', function () {
    $handler = Mockery::mock(WebhookHandler::class);
    $handler->shouldReceive('verify')->andReturnTrue();

    routerWith($handler)->handle('fake', 'not-json', [], webhookAccount());
})->throws(InvalidWebhookException::class);

it('rejects invalid signatures', function () {
    $handler = Mockery::mock(WebhookHandler::class);
    $handler->shouldReceive('verify')->andReturnFalse();

    routerWith($handler)->handle('fake', '{}', [], webhookAccount());
})->throws(InvalidWebhookException::class);

it('normalizes, dispatches once and dedupes by event id', function () {
    $received = [];
    $event = new PaymentEvent(
        PaymentEventType::Received, 'ch_1', 'ref', 1000, null, null, 'fake', 'evt_1', [],
    );
    $handler = Mockery::mock(WebhookHandler::class);
    $handler->shouldReceive('verify')->andReturnTrue();
    $handler->shouldReceive('normalize')->andReturn($event);

    $router = routerWith($handler, $received);

    expect($router->handle('fake', '{"a":1}', [], webhookAccount()))->toBe($event)
        ->and($router->handle('fake', '{"a":1}', [], webhookAccount()))->toBeNull()
        ->and($received)->toHaveCount(1);

    $router->release($event);

    expect($router->handle('fake', '{"a":1}', [], webhookAccount()))->toBe($event);
});

it('registers the Asaas and Stripe handlers from the plugins', function () {
    expect(app(WebhookRouter::class))->toBeInstanceOf(WebhookRouter::class);

    expect(fn () => app(WebhookRouter::class)->handle('stripe', '{}', [], webhookAccount()))
        ->toThrow(InvalidWebhookException::class, 'inválid');
    expect(fn () => app(WebhookRouter::class)->handle('zzz', '{}', [], webhookAccount()))
        ->toThrow(InvalidWebhookException::class, 'Nenhum handler');
});

it('resolves the resilience factory with config values', function () {
    config(['hub-financial.resilience.max_attempts' => 7]);

    expect(app(ResilientGatewayFactory::class))->not->toBeNull()
        ->and(app(HubFinancialConfig::class)->resilience()['max_attempts'])->toBe(7);
});
