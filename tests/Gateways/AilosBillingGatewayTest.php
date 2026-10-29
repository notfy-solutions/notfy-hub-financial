<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Notfy\HubFinancial\Credentials\ApiKey;
use Notfy\HubFinancial\Credentials\OAuth2;
use Notfy\HubFinancial\Domain\Enums\Capability;
use Notfy\HubFinancial\Domain\Enums\ChargeStatus;
use Notfy\HubFinancial\Domain\Enums\NextActionType;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;
use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\Exceptions\AilosApiException;
use Notfy\HubFinancial\Exceptions\UnsupportedGatewayRequestException;
use Notfy\HubFinancial\Gateways\Ailos\AilosBillingGateway;
use Notfy\HubFinancial\Gateways\Ailos\AilosEnvironment;
use Notfy\HubFinancial\Gateways\Ailos\AilosHttpClient;
use Notfy\HubFinancial\Gateways\Ailos\AilosPlugin;
use Notfy\HubFinancial\Support\GatewayAccount;

const AILOS_API = 'ailos.test/api';

function ailosGateway(): AilosBillingGateway
{
    return new AilosBillingGateway(
        new AilosHttpClient(new OAuth2('id', 'secret', 'https://ailos.test/token'), 'https://ailos.test/api'),
        '123456',
        1,
    );
}

function fakeAilos(array $routes): void
{
    Http::fake(array_merge(['ailos.test/token' => Http::response(['access_token' => 'tok'])], $routes));
}

function ailosPayer(array $overrides = []): array
{
    return ['pagadorResponse' => [array_merge([
        'entidadeLegal' => ['identificadorReceitaFederal' => '12345678900', 'tipoPessoa' => 'PessoaFisica', 'nome' => 'Fulano de Tal'],
        'telefone' => ['ddi' => '55', 'ddd' => '47', 'numero' => '999990000'],
        'emails' => [['endereco' => 'fulano@example.com']],
        'endereco' => [
            'cep' => '89037-710', 'logradouro' => 'Rua 123', 'numero' => 123, 'complemento' => 'Casa', 'bairro' => 'Centro',
            'cidade' => ['codigo' => '1', 'codigoMunicipioIbge' => 4202404, 'nome' => 'Blumenau', 'uf' => 'SC'],
        ],
        'mensagemPagador' => ['Mensagem original'],
    ], $overrides)]];
}

function ailosCharge(array $extra = [], array $overrides = []): ChargeInput
{
    return new ChargeInput(...array_merge([
        'customerExternalId' => '123.456.789-00',
        'amountCents' => 10050,
        'dueDate' => '2026-12-10',
        'paymentMethod' => PaymentMethod::Boleto,
        'description' => 'Mensalidade',
        'externalReference' => '42',
        'extra' => $extra,
    ], $overrides));
}

function ailosCustomer(array $overrides = []): CustomerInput
{
    return new CustomerInput(...array_merge([
        'name' => 'Fulano de Tal',
        'document' => '123.456.789-00',
        'email' => 'fulano@example.com',
        'phone' => '(47) 99999-0000',
        'postalCode' => '89037-710',
        'addressNumber' => '123',
        'addressLine' => 'Rua 123',
        'city' => 'Blumenau',
        'state' => 'sc',
        'neighborhood' => 'Centro',
    ], $overrides));
}

it('declares Brazil, BRL and boleto only', function () {
    $gateway = ailosGateway();

    expect($gateway->supportedCountries())->toBe(['BR'])
        ->and($gateway->supportedCurrencies())->toBe(['BRL'])
        ->and($gateway->supportedPaymentMethods())->toBe([PaymentMethod::Boleto])
        ->and($gateway->capabilities())->toContain(Capability::BankSlip)
        ->and($gateway->capabilities())->not->toContain(Capability::Pix, Capability::Webhook);
});

it('creates a boleto embedding the registered payer and maps the barcode to a voucher action', function () {
    fakeAilos([
        AILOS_API.'/v1/pagadores/consultar/12345678900' => Http::response(ailosPayer()),
        AILOS_API.'/v2/boletos/gerar/boleto/convenios/123456' => Http::response(['boleto' => [
            'documento' => ['numeroDocumento' => 42, 'nossoNumero' => '01234567000000042'],
            'vencimento' => ['dataVencimentoAtual' => '2099-12-10T00:00:00Z'],
            'indicadorSituacaoBoleto' => 0,
            'codigoBarras' => ['codigoBarras' => '08591...', 'linhaDigitavel' => '08591.01008'],
        ]]),
    ]);

    $result = ailosGateway()->createCharge(ailosCharge(
        ['instrucoes' => ['tipoMulta' => 2, 'valorMulta' => 2], 'mensagens' => [str_repeat('x', 60)], 'indicador_registro_nuclea' => 1, 'especie_documento' => 2],
    ));

    expect($result->externalId)->toBe('42')
        ->and($result->status)->toBe(ChargeStatus::Pending)
        ->and($result->nextAction?->type)->toBe(NextActionType::Voucher)
        ->and($result->nextAction?->data['linha_digitavel'])->toBe('08591.01008')
        ->and($result->nextAction?->data['nosso_numero'])->toBe('01234567000000042');

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/v2/boletos/gerar/boleto/convenios/123456')) {
            return false;
        }

        return $request['convenioCobranca']['codigoCarteiraCobranca'] === 1
            && $request['documento'] === ['numeroDocumento' => 42, 'descricaoDocumento' => '42', 'especieDocumento' => 2]
            && $request['emissao']['formaEmissao'] === 2
            && $request['vencimento']['dataVencimento'] === '2026-12-10'
            && $request['valorBoleto']['valorNominal'] === 100.5
            && $request['indicadorRegistroNuclea'] === 1
            && $request['instrucoes'] === ['tipoDesconto' => 3, 'tipoMulta' => 2, 'tipoJurosMora' => 3, 'valorMulta' => 2]
            && $request['pagador']['entidadeLegal'] === ['identificadorReceitaFederal' => '12345678900', 'tipoPessoa' => 1, 'nome' => 'Fulano de Tal']
            && $request['pagador']['endereco']['cep'] === '89037710'
            && $request['pagador']['endereco']['numero'] === '123'
            && $request['pagador']['endereco']['cidade'] === 'Blumenau'
            && $request['pagador']['endereco']['uf'] === 'SC'
            && strlen($request['pagador']['mensagemPagador'][0]) === 40;
    });
});

it('accepts a string city and a numero_boleto from extra, defaulting the status to pending without barcode', function () {
    fakeAilos([
        AILOS_API.'/v1/pagadores/consultar/12345678900' => Http::response(ailosPayer([
            'telefone' => [], 'emails' => [], 'mensagemPagador' => [],
            'endereco' => ['cep' => '89037710', 'logradouro' => 'Rua', 'numero' => 1, 'bairro' => 'B', 'cidade' => 'Gaspar'],
        ])),
        AILOS_API.'/v2/boletos/gerar/*' => Http::response([]),
    ]);

    $result = ailosGateway()->createCharge(ailosCharge(['numero_boleto' => 7], ['externalReference' => 'INV-ABC']));

    expect($result->externalId)->toBe('7')
        ->and($result->nextAction)->toBeNull();

    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'gerar')
        || ($request['pagador']['endereco']['cidade'] === 'Gaspar'
            && ! isset($request['pagador']['telefone'], $request['pagador']['emails'], $request['pagador']['mensagemPagador'])
            && ! isset($request['indicadorRegistroNuclea'])));
});

it('rejects invalid charges before calling the API', function (array $overrides, string $exception, string $message) {
    Http::fake();

    ailosGateway()->createCharge(ailosCharge([], $overrides));

    Http::assertNothingSent();
})->throws(InvalidArgumentException::class)->with([
    'currency' => [['currency' => 'USD'], UnsupportedGatewayRequestException::class, 'moeda'],
    'method' => [['paymentMethod' => PaymentMethod::Pix], UnsupportedGatewayRequestException::class, 'meio'],
    'due date' => [['dueDate' => null], InvalidArgumentException::class, 'vencimento'],
    'non numeric sequence' => [['externalReference' => 'INV-1'], InvalidArgumentException::class, 'sequencial'],
    'too long sequence' => [['externalReference' => '1234567890'], InvalidArgumentException::class, 'sequencial'],
    'zero sequence' => [['externalReference' => '0'], InvalidArgumentException::class, 'sequencial'],
]);

it('fails when the payer is not registered', function () {
    fakeAilos([AILOS_API.'/v1/pagadores/consultar/*' => Http::response(['pagadorResponse' => []])]);

    ailosGateway()->createCharge(ailosCharge());
})->throws(AilosApiException::class, 'Pagador 12345678900 não cadastrado no AILOS.');

it('fetches a boleto and maps liquidated, baixado, overdue and pending', function (int $situation, string $due, ChargeStatus $expected) {
    fakeAilos([AILOS_API.'/v2/boletos/consultar/boleto/convenios/123456/9' => Http::response(['boleto' => [
        'documento' => ['numeroDocumento' => 9],
        'vencimento' => ['dataVencimentoAtual' => $due],
        'indicadorSituacaoBoleto' => $situation,
    ]])]);

    $result = ailosGateway()->fetchCharge('9');

    expect($result->externalId)->toBe('9')->and($result->status)->toBe($expected);
})->with([
    'liquidated' => [5, '2020-01-01T00:00:00Z', ChargeStatus::Received],
    'baixado' => [3, '2020-01-01T00:00:00Z', ChargeStatus::Deleted],
    'overdue' => [0, '2020-01-01T00:00:00Z', ChargeStatus::Overdue],
    'pending' => [0, '2099-01-01T00:00:00Z', ChargeStatus::Pending],
]);

it('treats a boleto without due date as pending and uses the requested id when the response has none', function () {
    fakeAilos([AILOS_API.'/v2/boletos/consultar/*' => Http::response(['boleto' => ['indicadorSituacaoBoleto' => 0]])]);

    $result = ailosGateway()->fetchCharge('15');

    expect($result->externalId)->toBe('15')->and($result->status)->toBe(ChargeStatus::Pending);
});

it('refuses operations the manual does not document', function () {
    $gateway = ailosGateway();

    expect(fn () => $gateway->cancelCharge('1'))->toThrow(UnsupportedGatewayRequestException::class)
        ->and(fn () => $gateway->refundCharge('1'))->toThrow(UnsupportedGatewayRequestException::class)
        ->and(fn () => $gateway->createCheckoutSession([]))->toThrow(UnsupportedGatewayRequestException::class)
        ->and(fn () => $gateway->createBillingPortalSession('1', 'https://x'))->toThrow(UnsupportedGatewayRequestException::class)
        ->and($gateway->deleteCustomer('12345678900'))->toBeFalse();
});

it('registers and updates a payer identified by cpf/cnpj', function () {
    fakeAilos([
        AILOS_API.'/v1/pagadores/cadastrar' => Http::response(['ok' => 1]),
        AILOS_API.'/v1/pagadores/alterar' => Http::response(['ok' => 2]),
    ]);

    $created = ailosGateway()->createCustomer(ailosCustomer());
    $updated = ailosGateway()->updateCustomer('123.456.789-00', ailosCustomer(['document' => '12.345.678/0001-95', 'phone' => null, 'email' => null, 'addressNumber' => null]));

    expect($created->externalId)->toBe('12345678900')
        ->and($updated->externalId)->toBe('12345678900');

    Http::assertSent(fn ($request) => ! str_ends_with($request->url(), 'cadastrar')
        || ($request['pagador']['entidadeLegal']['tipoPessoa'] === 1
            && $request['pagador']['telefone'] === ['ddi' => '55', 'ddd' => '47', 'numero' => '999990000']
            && $request['pagador']['emails'] === [['endereco' => 'fulano@example.com']]
            && $request['pagador']['endereco']['uf'] === 'SC'));
    Http::assertSent(fn ($request) => ! str_ends_with($request->url(), 'alterar')
        || ($request['pagador']['entidadeLegal']['tipoPessoa'] === 2
            && ! isset($request['pagador']['telefone'], $request['pagador']['emails'])
            && ! isset($request['pagador']['endereco']['numero'])));
});

it('requires the address fields the AILOS payer needs', function (string $field) {
    Http::fake();

    ailosGateway()->createCustomer(ailosCustomer([$field => $field === 'document' ? '' : null]));
})->throws(InvalidArgumentException::class, 'O pagador AILOS exige')->with([
    'document' => ['document'], 'postalCode' => ['postalCode'], 'addressLine' => ['addressLine'],
    'neighborhood' => ['neighborhood'], 'city' => ['city'], 'state' => ['state'],
]);

it('generates boletos in batch and reads the batch result, returning null while processing', function () {
    fakeAilos([
        AILOS_API.'/v1/pagadores/consultar/*' => Http::response(ailosPayer()),
        AILOS_API.'/v2/boletos/gerar/boleto/lote/convenios/123456' => Http::response(['ticketLote' => 'T-1'], 202),
        AILOS_API.'/v1/boletos/consultar/boleto/lote/convenios/123456/T-1' => Http::sequence()
            ->push(['message' => '[99] - Lote está em processamento.'], 400)
            ->push(['ticketLote' => 'T-1', 'boletos' => []]),
        AILOS_API.'/v1/boletos/consultar/boleto/lote/convenios/123456/T-2' => Http::response(['message' => '[10] - Lote inexistente'], 400),
    ]);

    $gateway = ailosGateway();
    $ticket = $gateway->createBatch([ailosCharge(['numero_boleto' => 1]), ailosCharge(['numero_boleto' => 2])]);

    expect($ticket)->toBe('T-1')
        ->and($gateway->fetchBatch('T-1'))->toBeNull()
        ->and($gateway->fetchBatch('T-1'))->toBe(['ticketLote' => 'T-1', 'boletos' => []])
        ->and(fn () => $gateway->fetchBatch('T-2'))->toThrow(AilosApiException::class, 'Lote inexistente');

    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'lote/convenios/123456') || $request->method() !== 'POST'
        || (count($request->data()) === 2 && $request[1]['documento']['numeroDocumento'] === 2));
});

it('fails when a batch or return request comes back without a ticket', function () {
    fakeAilos([AILOS_API.'/v1/boletos/solicitar/*' => Http::response(['ticketLote' => ''])]);

    ailosGateway()->requestReturnFile('2026-10-27');
})->throws(AilosApiException::class, "sem 'ticketLote'");

it('requests, lists and downloads return files', function () {
    fakeAilos([
        AILOS_API.'/v1/boletos/solicitar/arquivo/retorno/convenios/123456/2026-10-27' => Http::response(['ticketLote' => 'R-1']),
        AILOS_API.'/v1/boletos/listar/arquivo/retorno/convenios/123456/2026-10-01/2026-10-27' => Http::response(['arquivosRetorno' => [['numeroRetorno' => 1, 'registros' => 3]]]),
        AILOS_API.'/v1/boletos/baixar/arquivo/retorno/convenios/123456/R-1' => Http::sequence()
            ->push(['message' => '[99] - Solicitação em processamento.'], 400)
            ->push('ZIPDATA', 200, ['Content-Type' => 'application/zip']),
        AILOS_API.'/v1/boletos/baixar/arquivo/retorno/convenios/123456/R-9' => Http::response(['message' => 'Erro'], 500),
    ]);

    $gateway = ailosGateway();

    expect($gateway->requestReturnFile('2026-10-27'))->toBe('R-1')
        ->and($gateway->listReturnFiles('2026-10-01', '2026-10-27'))->toBe([['numeroRetorno' => 1, 'registros' => 3]])
        ->and($gateway->downloadReturnFile('R-1'))->toBeNull()
        ->and($gateway->downloadReturnFile('R-1'))->toBe('ZIPDATA')
        ->and(fn () => $gateway->downloadReturnFile('R-9'))->toThrow(AilosApiException::class);
});

describe('plugin', function () {
    $credentials = fn () => new OAuth2('id', 'secret', 'https://ailos.test/token');

    it('is named ailos and builds a gateway from the account options', function () use ($credentials) {
        $plugin = new AilosPlugin;
        $gateway = $plugin->build(new GatewayAccount('ailos', $credentials(), options: ['convenio' => '123.456', 'carteira' => 2, 'base_url' => 'https://ailos.test/api']));

        expect($plugin->name())->toBe('ailos')->and($gateway)->toBeInstanceOf(AilosBillingGateway::class);
    });

    it('defaults to the sandbox url', function () use ($credentials) {
        $gateway = (new AilosPlugin)->build(new GatewayAccount('ailos', $credentials(), options: ['convenio' => '1']));

        expect($gateway)->toBeInstanceOf(AilosBillingGateway::class)
            ->and(AilosEnvironment::fromConfig(null))->toBe(AilosEnvironment::Sandbox)
            ->and(AilosEnvironment::fromConfig('production'))->toBe(AilosEnvironment::Production)
            ->and(AilosEnvironment::fromConfig('nope'))->toBe(AilosEnvironment::Sandbox)
            ->and(AilosEnvironment::Production->defaultBaseUrl())->toBeNull();
    });

    it('requires a base_url in production', function () use ($credentials) {
        (new AilosPlugin)->build(new GatewayAccount('ailos', $credentials(), options: ['convenio' => '1', 'environment' => 'production']));
    })->throws(RuntimeException::class, 'URL base do AILOS');

    it('requires OAuth2 credentials and a convenio', function (GatewayAccount $account) {
        (new AilosPlugin)->build($account);
    })->throws(InvalidArgumentException::class)->with([
        'api key' => fn () => new GatewayAccount('ailos', new ApiKey('k'), options: ['convenio' => '1']),
        'no convenio' => fn () => new GatewayAccount('ailos', new OAuth2('id', 'secret', 'https://ailos.test/token')),
    ]);
});
