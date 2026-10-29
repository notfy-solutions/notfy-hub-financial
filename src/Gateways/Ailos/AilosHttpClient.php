<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Ailos;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Notfy\HubFinancial\Credentials\OAuth2;
use Notfy\HubFinancial\Exceptions\AilosApiException;
use Notfy\HubFinancial\Support\ApiPayload;
use RuntimeException;

/**
 * Wrapper HTTP fino para a API de Cobrança do Sistema AILOS (WSO2, OAuth2
 * client_credentials). O token fica só na instância, renovado perto de
 * expirar; sem estado global, seguro para multi-tenant e Octane.
 */
final class AilosHttpClient
{
    private const TOKEN_MARGIN_SECONDS = 30;

    private const DEFAULT_TOKEN_TTL_SECONDS = 300;

    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly OAuth2 $credentials,
        private readonly string $baseUrl,
    ) {
        $required = [
            'Client ID do AILOS não informado.' => $this->credentials->clientId,
            'Client secret do AILOS não informado.' => $this->credentials->clientSecret,
            'URL de token do AILOS não informada.' => $this->credentials->tokenUrl,
            'URL base do AILOS não informada (opção base_url da conta).' => $this->baseUrl,
        ];

        $message = array_search('', array_map(trim(...), $required), true);
        if ($message !== false) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->handle($this->client()->get($endpoint, $query));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->handle($this->client()->post($endpoint, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function put(string $endpoint, array $data = []): array
    {
        return $this->handle($this->client()->put($endpoint, $data));
    }

    /** Corpo bruto da resposta, para downloads (arquivo de retorno em zip). */
    public function download(string $endpoint): string
    {
        $response = $this->client()->get($endpoint);
        $this->handle($response);

        return $response->body();
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->withHeaders(['User-Agent' => 'notfy-hub-financial'])
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->timeout(15);
    }

    private function accessToken(): string
    {
        if ($this->token !== null && time() < $this->tokenExpiresAt) {
            return $this->token;
        }

        $form = ['grant_type' => 'client_credentials'];
        if ($this->credentials->scope !== '') {
            $form['scope'] = $this->credentials->scope;
        }

        $response = Http::asForm()
            ->withBasicAuth($this->credentials->clientId, $this->credentials->clientSecret)
            ->acceptJson()
            ->timeout(15)
            ->post($this->credentials->tokenUrl, $form);

        $token = ApiPayload::optionalString($response->json('access_token'));

        if ($response->failed() || $token === null || $token === '') {
            throw new AilosApiException(
                'Falha ao obter token de acesso do AILOS.',
                $response->status(),
                ApiPayload::toAssocArray($response->json()),
            );
        }

        $this->token = $token;
        $this->tokenExpiresAt = time()
            + max(0, ApiPayload::toInt($response->json('expires_in'), self::DEFAULT_TOKEN_TTL_SECONDS) - self::TOKEN_MARGIN_SECONDS);

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(Response $response): array
    {
        $data = ApiPayload::toAssocArray($response->json());

        if ($response->failed()) {
            $message = ApiPayload::optionalString($response->json('message'))
                ?? ApiPayload::optionalString($response->json('details.0.message'))
                ?? $response->body();

            throw new AilosApiException($message, $response->status(), $data);
        }

        return $data;
    }
}
