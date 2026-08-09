<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Notfy\HubFinancial\DTO\NotaFiscalInput;
use Notfy\HubFinancial\DTO\NotaFiscalTaxes;
use Notfy\HubFinancial\Gateways\Asaas\AsaasHttpClient;
use Notfy\HubFinancial\Gateways\Asaas\AsaasNotaFiscalService;

function notaFiscalService(): AsaasNotaFiscalService
{
    return new AsaasNotaFiscalService(new AsaasHttpClient('test-key', 'https://api-sandbox.asaas.com/v3'));
}

it('emits a nota fiscal and returns authorized status', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/invoices' => Http::response([
            'id' => 'inv_123',
            'status' => 'AUTHORIZED',
            'number' => '42',
            'pdfUrl' => 'https://asaas.com/nf/inv_123.pdf',
        ], 200),
    ]);

    $result = notaFiscalService()->emit(new NotaFiscalInput(
        serviceDescription: 'Licenciamento de software',
        observations: 'Fatura 001',
        serviceValueCents: 50000,
        effectiveDate: '2026-09-01',
        municipalServiceName: 'Desenvolvimento de software',
        taxes: new NotaFiscalTaxes(iss: 5.0),
        paymentExternalId: 'pay_123',
        municipalServiceCode: '1.05',
    ));

    expect($result->externalId)->toBe('inv_123')
        ->and($result->status)->toBe('AUTHORIZED')
        ->and($result->number)->toBe('42');

    Http::assertSent(fn ($request) => $request['payment'] === 'pay_123'
        && $request['taxes']['iss'] === 5.0
        && $request['value'] === 500.0);
});

it('exposes error message when emission fails', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/invoices' => Http::response([
            'id' => 'inv_err',
            'status' => 'ERROR',
            'errors' => [['description' => 'Serviço municipal inválido']],
        ], 200),
    ]);

    $result = notaFiscalService()->emit(new NotaFiscalInput(
        serviceDescription: 'Licenciamento de software',
        observations: 'Fatura 001',
        serviceValueCents: 50000,
        effectiveDate: '2026-09-01',
        municipalServiceName: 'Desenvolvimento de software',
        taxes: new NotaFiscalTaxes,
        customerExternalId: 'cus_123',
    ));

    expect($result->status)->toBe('ERROR')
        ->and($result->errorMessage)->toBe('Serviço municipal inválido');
});

it('cancels a nota fiscal', function () {
    Http::fake([
        'api-sandbox.asaas.com/v3/invoices/inv_123/cancel' => Http::response(['status' => 'PROCESSING_CANCELLATION'], 200),
    ]);

    expect(notaFiscalService()->cancel('inv_123'))->toBeTrue();
});
