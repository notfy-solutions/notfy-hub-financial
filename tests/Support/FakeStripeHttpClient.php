<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Tests\Support;

use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

/**
 * Test double for the official Stripe SDK's HTTP layer. Registered globally
 * via `\Stripe\ApiRequestor::setHttpClient()`, it lets tests exercise the
 * real `StripeClient`/service classes (serialization, typed response
 * objects, error mapping) while faking only the network call underneath —
 * the same pattern Stripe's own SDK test suite uses.
 */
final class FakeStripeHttpClient implements ClientInterface
{
    /**
     * @var list<array{method: string, url: string, params: array<string, mixed>, headers: list<string>}>
     */
    public array $requests = [];

    /**
     * @var list<array{method: string, urlContains: string, body: array<string, mixed>, status: int}>
     */
    private array $queue = [];

    /**
     * @param  array<string, mixed>  $body
     */
    public function queueResponse(string $method, string $urlContains, array $body, int $status = 200): void
    {
        $this->queue[] = [
            'method' => strtolower($method),
            'urlContains' => $urlContains,
            'body' => $body,
            'status' => $status,
        ];
    }

    /**
     * @param  'delete'|'get'|'post'  $method
     * @param  array  $headers
     * @param  array  $params
     * @param  'v1'|'v2'  $apiMode
     * @return array{0: string, 1: int, 2: array}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtolower((string) $method);

        $this->requests[] = [
            'method' => $method,
            'url' => (string) $absUrl,
            'params' => (array) $params,
            'headers' => array_values((array) $headers),
        ];

        foreach ($this->queue as $index => $entry) {
            if ($entry['method'] === $method && str_contains((string) $absUrl, $entry['urlContains'])) {
                unset($this->queue[$index]);

                return [(string) json_encode($entry['body']), $entry['status'], []];
            }
        }

        throw new ApiConnectionException("FakeStripeHttpClient: no response queued for {$method} {$absUrl}");
    }

    public function hasHeader(int $requestIndex, string $name, ?string $value = null): bool
    {
        foreach ($this->requests[$requestIndex]['headers'] ?? [] as $header) {
            if (! str_starts_with(strtolower($header), strtolower($name).':')) {
                continue;
            }

            if ($value === null) {
                return true;
            }

            return trim(substr($header, strlen($name) + 1)) === $value;
        }

        return false;
    }

    public function reset(): void
    {
        $this->requests = [];
        $this->queue = [];
    }
}
