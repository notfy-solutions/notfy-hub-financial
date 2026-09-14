<?php

declare(strict_types=1);

use Notfy\HubFinancial\Exceptions\AsaasApiException;

it('exposes the status code and payload of a failed request', function () {
    $exception = new AsaasApiException('Cliente não encontrado', 404, ['errors' => [['description' => 'Cliente não encontrado']]]);

    expect($exception->getMessage())->toBe('Cliente não encontrado')
        ->and($exception->getStatusCode())->toBe(404)
        ->and($exception->getPayload())->toBe(['errors' => [['description' => 'Cliente não encontrado']]]);
});

it('defaults the status code and payload when not provided', function () {
    $exception = new AsaasApiException('Erro genérico');

    expect($exception->getStatusCode())->toBe(0)
        ->and($exception->getPayload())->toBe([]);
});
