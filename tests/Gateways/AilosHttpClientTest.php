<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Notfy\HubFinancial\Credentials\OAuth2;
use Notfy\HubFinancial\Exceptions\AilosApiException;
use Notfy\HubFinancial\Gateways\Ailos\AilosHttpClient;

const AILOS_BASE = 'https://ailos.test/api';
const AILOS_TOKEN = 'https://ailos.test/token';

function ailosClient(string $scope = ''): AilosHttpClient
{
    return new AilosHttpClient(new OAuth2('id', 'secret', AILOS_TOKEN, $scope), AILOS_BASE);
}

it('rejects blank credentials, token url and base url', function (OAuth2 $credentials, string $baseUrl, string $message) {
    expect(fn () => new AilosHttpClient($credentials, $baseUrl))->toThrow(RuntimeException::class, $message);
})->with([
    'client id' => [new OAuth2(' ', 'secret', AILOS_TOKEN), AILOS_BASE, 'Client ID do AILOS não informado.'],
    'client secret' => [new OAuth2('id', '', AILOS_TOKEN), AILOS_BASE, 'Client secret do AILOS não informado.'],
    'token url' => [new OAuth2('id', 'secret', ' '), AILOS_BASE, 'URL de token do AILOS não informada.'],
    'base url' => [new OAuth2('id', 'secret', AILOS_TOKEN), ' ', 'URL base do AILOS não informada'],
]);

it('fetches a client_credentials token with basic auth and reuses it', function () {
    Http::fake([
        AILOS_TOKEN => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'ailos.test/api/*' => Http::response(['ok' => true]),
    ]);

    $client = ailosClient('cobranca');
    $client->get('ping');
    $client->post('ping', ['a' => 1]);
    $client->put('ping', ['a' => 1]);

    Http::assertSentCount(4);
    Http::assertSent(fn ($request) => $request->url() === AILOS_TOKEN
        && $request['grant_type'] === 'client_credentials'
        && $request['scope'] === 'cobranca'
        && str_starts_with($request->header('Authorization')[0], 'Basic '));
    Http::assertSent(fn ($request) => $request->url() === AILOS_BASE.'/ping'
        && $request->header('Authorization')[0] === 'Bearer tok-1');
});

it('omits the scope when none is configured and renews an expired token', function () {
    Http::fake([
        AILOS_TOKEN => Http::sequence()
            ->push(['access_token' => 'tok-1', 'expires_in' => 0])
            ->push(['access_token' => 'tok-2']),
        'ailos.test/api/*' => Http::response(['ok' => true]),
    ]);

    $client = ailosClient();
    $client->get('a');
    $client->get('b');

    Http::assertSent(fn ($request) => $request->url() === AILOS_TOKEN && ! isset($request['scope']));
    Http::assertSent(fn ($request) => $request->url() === AILOS_BASE.'/b'
        && $request->header('Authorization')[0] === 'Bearer tok-2');
});

it('throws when the token endpoint fails or returns no token', function (int $status, array $body) {
    Http::fake([AILOS_TOKEN => Http::response($body, $status)]);

    ailosClient()->get('a');
})->throws(AilosApiException::class, 'Falha ao obter token de acesso do AILOS.')->with([
    'unauthorized' => [401, ['error' => 'invalid_client']],
    'no token' => [200, ['expires_in' => 10]],
]);

it('extracts the error message, falls back to details and then to the body', function () {
    Http::fake([
        AILOS_TOKEN => Http::response(['access_token' => 't']),
        'ailos.test/api/top' => Http::response(['message' => '[01] - Convênio inválido'], 400),
        'ailos.test/api/detail' => Http::response(['details' => [['message' => 'detalhe']]], 422),
        'ailos.test/api/raw' => Http::response('Gateway Timeout', 504),
    ]);

    $client = ailosClient();
    $catch = function (string $endpoint) use ($client): AilosApiException {
        try {
            $client->get($endpoint);
        } catch (AilosApiException $exception) {
            return $exception;
        }

        throw new LogicException('Expected AilosApiException.');
    };

    expect($catch('top')->getMessage())->toBe('[01] - Convênio inválido')
        ->and($catch('top')->getStatusCode())->toBe(400)
        ->and($catch('detail')->getMessage())->toBe('detalhe')
        ->and($catch('raw')->getMessage())->toBe('Gateway Timeout');
});

it('downloads the raw body', function () {
    Http::fake([
        AILOS_TOKEN => Http::response(['access_token' => 't']),
        'ailos.test/api/file' => Http::response('ZIPDATA', 200, ['Content-Type' => 'application/zip']),
    ]);

    expect(ailosClient()->download('file'))->toBe('ZIPDATA');
});
