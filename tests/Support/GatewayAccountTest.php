<?php

declare(strict_types=1);

use Notfy\HubFinancial\Credentials\ApiKey;
use Notfy\HubFinancial\Credentials\MutualTls;
use Notfy\HubFinancial\Credentials\OAuth2;
use Notfy\HubFinancial\Support\GatewayAccount;

it('stores provider, credentials, webhook secret and options', function () {
    $creds = new ApiKey('api-key-123');
    $account = new GatewayAccount(
        provider: 'asaas',
        credentials: $creds,
        webhookSecret: 'wh_secret',
        options: ['base_url' => 'https://api.asaas.com/v3'],
    );

    expect($account->provider)->toBe('asaas')
        ->and($account->credentials)->toBe($creds)
        ->and($account->webhookSecret)->toBe('wh_secret')
        ->and($account->options['base_url'])->toBe('https://api.asaas.com/v3');
});

it('defaults webhookSecret to null and options to empty array', function () {
    $account = new GatewayAccount('stripe', new ApiKey('sk_key'));

    expect($account->webhookSecret)->toBeNull()
        ->and($account->options)->toBe([]);
});

it('accepts ApiKey credentials', function () {
    $creds = new ApiKey('my-key');

    expect($creds->key)->toBe('my-key');
});

it('accepts OAuth2 credentials', function () {
    $creds = new OAuth2(
        clientId: 'client_id',
        clientSecret: 'client_secret',
        tokenUrl: 'https://auth.example.com/token',
        scope: 'payments',
    );

    expect($creds->clientId)->toBe('client_id')
        ->and($creds->clientSecret)->toBe('client_secret')
        ->and($creds->tokenUrl)->toBe('https://auth.example.com/token')
        ->and($creds->scope)->toBe('payments');
});

it('accepts OAuth2 credentials with empty scope default', function () {
    $creds = new OAuth2('id', 'secret', 'https://token');

    expect($creds->scope)->toBe('');
});

it('accepts MutualTls credentials', function () {
    $creds = new MutualTls(
        certificate: '-----BEGIN CERTIFICATE-----',
        privateKey: '-----BEGIN PRIVATE KEY-----',
        caCertificate: '-----BEGIN CA CERT-----',
    );

    expect($creds->certificate)->toBe('-----BEGIN CERTIFICATE-----')
        ->and($creds->privateKey)->toBe('-----BEGIN PRIVATE KEY-----')
        ->and($creds->caCertificate)->toBe('-----BEGIN CA CERT-----');
});

it('accepts MutualTls credentials without a CA certificate', function () {
    $creds = new MutualTls('cert', 'key');

    expect($creds->caCertificate)->toBeNull();
});
