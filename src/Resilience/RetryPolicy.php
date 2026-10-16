<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Resilience;

use Illuminate\Http\Client\ConnectionException;
use Notfy\HubFinancial\Exceptions\GatewayException;
use Throwable;

final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMs = 200,
        public int $maxDelayMs = 5000,
    ) {}

    /**
     * Falha transitória (rede, rate limit, 5xx). Não inclui erros 4xx de negócio.
     */
    public static function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof GatewayException) {
            return false;
        }

        $status = $exception->getStatusCode();

        return $status === 0 || $status === 408 || $status === 429 || $status >= 500;
    }

    /**
     * Pode repetir sem risco de cobrar duas vezes? Leituras: qualquer falha
     * transitória. Escritas: só 429, em que o provedor garantidamente não processou.
     */
    public function shouldRetry(Throwable $exception, bool $isRead, int $attempt): bool
    {
        if ($attempt >= $this->maxAttempts || ! self::isTransient($exception)) {
            return false;
        }

        if ($isRead) {
            return true;
        }

        return $exception instanceof GatewayException && $exception->getStatusCode() === 429;
    }

    /** Backoff exponencial com jitter. */
    public function delayMs(int $attempt): int
    {
        $delay = min($this->maxDelayMs, $this->baseDelayMs * (2 ** ($attempt - 1)));

        return random_int(intdiv($delay, 2), $delay);
    }
}
