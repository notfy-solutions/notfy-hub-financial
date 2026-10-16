<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Resilience;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\ChargeResult;
use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\DTO\CustomerResult;
use Notfy\HubFinancial\Events\CircuitBreakerOpened;
use Notfy\HubFinancial\Events\GatewayCallFailed;
use Notfy\HubFinancial\Exceptions\GatewayUnavailableException;
use Throwable;

/**
 * Decorator que adiciona retry com backoff, circuit breaker e eventos de
 * observabilidade a qualquer BillingGateway, sem o driver saber disso.
 */
final class ResilientGateway implements BillingGateway
{
    /** @var Closure(int): void */
    private readonly Closure $sleeper;

    /**
     * @param  (Closure(int): void)|null  $sleeper  Recebe milissegundos; injetável em testes.
     */
    public function __construct(
        private readonly BillingGateway $inner,
        private readonly string $name,
        private readonly RetryPolicy $retry,
        private readonly CircuitBreaker $breaker,
        private readonly ?Dispatcher $events = null,
        ?Closure $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public function createCharge(ChargeInput $input): ChargeResult
    {
        return $this->call('createCharge', false, fn (): ChargeResult => $this->inner->createCharge($input));
    }

    public function fetchCharge(string $externalId): ChargeResult
    {
        return $this->call('fetchCharge', true, fn (): ChargeResult => $this->inner->fetchCharge($externalId));
    }

    public function refundCharge(string $externalId, ?int $amountCents = null): bool
    {
        return $this->call('refundCharge', false, fn (): bool => $this->inner->refundCharge($externalId, $amountCents));
    }

    public function cancelCharge(string $externalId): bool
    {
        return $this->call('cancelCharge', false, fn (): bool => $this->inner->cancelCharge($externalId));
    }

    public function createCheckoutSession(array $params): array
    {
        return $this->call('createCheckoutSession', false, fn (): array => $this->inner->createCheckoutSession($params));
    }

    public function createBillingPortalSession(string $customerExternalId, string $returnUrl): array
    {
        return $this->call(
            'createBillingPortalSession',
            false,
            fn (): array => $this->inner->createBillingPortalSession($customerExternalId, $returnUrl),
        );
    }

    public function createCustomer(CustomerInput $input): CustomerResult
    {
        return $this->call('createCustomer', false, fn (): CustomerResult => $this->inner->createCustomer($input));
    }

    public function updateCustomer(string $externalId, CustomerInput $input): CustomerResult
    {
        return $this->call('updateCustomer', false, fn (): CustomerResult => $this->inner->updateCustomer($externalId, $input));
    }

    public function deleteCustomer(string $externalId): bool
    {
        return $this->call('deleteCustomer', false, fn (): bool => $this->inner->deleteCustomer($externalId));
    }

    public function capabilities(): array
    {
        return $this->inner->capabilities();
    }

    public function supportedCountries(): array
    {
        return $this->inner->supportedCountries();
    }

    public function supportedCurrencies(): array
    {
        return $this->inner->supportedCurrencies();
    }

    public function supportedPaymentMethods(): array
    {
        return $this->inner->supportedPaymentMethods();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function call(string $name, bool $isRead, Closure $operation): mixed
    {
        if ($this->breaker->isOpen($this->name)) {
            throw GatewayUnavailableException::circuitOpen($this->name);
        }

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $result = $operation();
                $this->breaker->recordSuccess($this->name);

                return $result;
            } catch (Throwable $exception) {
                $willRetry = $this->retry->shouldRetry($exception, $isRead, $attempt);
                $this->events?->dispatch(new GatewayCallFailed($this->name, $name, $attempt, $willRetry, $exception));

                if (RetryPolicy::isTransient($exception) && $this->breaker->recordFailure($this->name)) {
                    $this->events?->dispatch(new CircuitBreakerOpened($this->name));
                }

                if (! $willRetry) {
                    throw $exception;
                }

                ($this->sleeper)($this->retry->delayMs($attempt));
            }
        }
    }
}
