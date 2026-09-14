<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Notfy\HubFinancial\Exceptions\AsaasApiException;
use Notfy\HubFinancial\Gateways\Asaas\AsaasHttpClient;

it('throws when instantiated with a blank api key', function () {
    new AsaasHttpClient('   ');
})->throws(RuntimeException::class, 'Asaas API key não informada.');

it('extracts the error description from the errors list on failure', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/customers/cus_err' => Http::response(['errors' => [['description' => 'Cliente não encontrado']]], 404),
    ]);

    $client = new AsaasHttpClient('test-key', 'https://api-sandbox.asaas.com/v3');

    try {
        $client->get('customers/cus_err');
        test()->fail('Expected AsaasApiException to be thrown.');
    } catch (AsaasApiException $exception) {
        expect($exception->getMessage())->toBe('Cliente não encontrado')
            ->and($exception->getStatusCode())->toBe(404);
    }
});

it('falls back to the message field when there is no errors list', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/customers/cus_err2' => Http::response(['message' => 'Não autorizado'], 401),
    ]);

    $client = new AsaasHttpClient('test-key', 'https://api-sandbox.asaas.com/v3');

    try {
        $client->get('customers/cus_err2');
        test()->fail('Expected AsaasApiException to be thrown.');
    } catch (AsaasApiException $exception) {
        expect($exception->getMessage())->toBe('Não autorizado');
    }
});

it('falls back to the raw response body when there is no structured error message', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/customers/cus_err3' => Http::response('Internal Server Error', 500),
    ]);

    $client = new AsaasHttpClient('test-key', 'https://api-sandbox.asaas.com/v3');

    try {
        $client->get('customers/cus_err3');
        test()->fail('Expected AsaasApiException to be thrown.');
    } catch (AsaasApiException $exception) {
        expect($exception->getMessage())->toBe('Internal Server Error');
    }
});
