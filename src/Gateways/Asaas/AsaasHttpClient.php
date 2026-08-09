<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Asaas;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Notfy\HubFinancial\Exceptions\AsaasApiException;
use RuntimeException;

/**
 * Wrapper HTTP fino para a API do Asaas (v3). Cada instância carrega a própria
 * chave de API — permite tanto o uso com a chave da própria empresa (conta
 * notfy) quanto com a chave de um Team/cliente (BYO key), sem singleton global.
 */
final class AsaasHttpClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.asaas.com/v3',
    ) {
        if (trim($this->apiKey) === '') {
            throw new RuntimeException('Asaas API key não informada.');
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
     * @param  array<string, mixed>  $data
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function delete(string $endpoint, array $data = []): array
    {
        return $this->handle($this->client()->delete($endpoint, $data));
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'access_token' => $this->apiKey,
            'User-Agent' => 'notfy-hub-financial',
        ])
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->timeout(15);
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(Response $response): array
    {
        if ($response->failed()) {
            $message = (string) ($response->json('errors.0.description') ?? $response->json('message') ?? $response->body());

            throw new AsaasApiException($message, $response->status(), (array) ($response->json() ?? []));
        }

        return (array) ($response->json() ?? []);
    }
}
