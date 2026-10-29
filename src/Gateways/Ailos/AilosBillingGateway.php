<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Ailos;

use Notfy\HubFinancial\Contracts\BillingGateway;
use Notfy\HubFinancial\Domain\Enums\Capability;
use Notfy\HubFinancial\Domain\Enums\PaymentMethod;
use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\ChargeResult;
use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\DTO\CustomerResult;
use Notfy\HubFinancial\Exceptions\AilosApiException;
use Notfy\HubFinancial\Exceptions\UnsupportedGatewayRequestException;
use Notfy\HubFinancial\Support\ApiPayload;
use Notfy\HubFinancial\Support\DefaultGatewayContext;
use Notfy\HubFinancial\Support\GatewayRequirements;

/**
 * Boleto bancário 085 do Sistema AILOS (API de Cobrança v2). Só Brasil e BRL.
 *
 * Identificadores: o pagador é identificado pelo CPF/CNPJ (só dígitos) e o
 * boleto pelo `numeroBoleto` (sequencial de até 9 dígitos, que o AILOS exige
 * não repetir nem retroceder). O sequencial vem de `extra['numero_boleto']`
 * ou, se numérico, do `externalReference`.
 *
 * O manual não documenta baixa, estorno nem exclusão de pagador, então
 * cancelCharge/refundCharge recusam e deleteCustomer devolve false.
 */
final class AilosBillingGateway implements BillingGateway
{
    private const string PROCESSING_MARKER = '[99]';

    private readonly AilosBoletoMapper $mapper;

    public function __construct(
        private readonly AilosHttpClient $client,
        private readonly string $convenio,
        int $carteira = 1,
    ) {
        $this->mapper = new AilosBoletoMapper($carteira);
    }

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return [Capability::BankSlip, Capability::OwnBankAccount, Capability::CnabRemittance];
    }

    /**
     * @return list<string>
     */
    public function supportedCountries(): array
    {
        return ['BR'];
    }

    /**
     * @return list<string>
     */
    public function supportedCurrencies(): array
    {
        return ['BRL'];
    }

    /**
     * @return list<PaymentMethod>
     */
    public function supportedPaymentMethods(): array
    {
        return [PaymentMethod::Boleto];
    }

    public function createCustomer(CustomerInput $input): CustomerResult
    {
        $response = $this->client->post('v1/pagadores/cadastrar', ['pagador' => $this->mapper->payer($input)]);

        return new CustomerResult($this->mapper->digits($input->document), $response);
    }

    public function updateCustomer(string $externalId, CustomerInput $input): CustomerResult
    {
        $response = $this->client->put('v1/pagadores/alterar', ['pagador' => $this->mapper->payer($input)]);

        return new CustomerResult($this->mapper->digits($externalId), $response);
    }

    /** O AILOS não expõe exclusão de pagador; ele só pode ficar inativo. */
    public function deleteCustomer(string $externalId): bool
    {
        return false;
    }

    public function createCharge(ChargeInput $input): ChargeResult
    {
        $response = $this->client->post("v2/boletos/gerar/boleto/convenios/{$this->convenio}", $this->boletoPayload($input));

        return $this->mapper->chargeResult($response, (string) $this->mapper->boletoNumber($input));
    }

    public function fetchCharge(string $externalId): ChargeResult
    {
        $response = $this->client->get("v2/boletos/consultar/boleto/convenios/{$this->convenio}/{$externalId}");

        return $this->mapper->chargeResult($response, $externalId);
    }

    public function refundCharge(string $externalId, ?int $amountCents = null): bool
    {
        throw UnsupportedGatewayRequestException::forCapability(Capability::Refund, self::class);
    }

    public function cancelCharge(string $externalId): bool
    {
        throw UnsupportedGatewayRequestException::forCapability(Capability::BankSlip, self::class);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{id: ?string, url: ?string, raw: array<string, mixed>}
     */
    public function createCheckoutSession(array $params): array
    {
        throw UnsupportedGatewayRequestException::forCapability(Capability::Checkout, self::class);
    }

    /**
     * @return array{url: ?string, raw: array<string, mixed>}
     */
    public function createBillingPortalSession(string $customerExternalId, string $returnUrl): array
    {
        throw UnsupportedGatewayRequestException::forCapability(Capability::BillingPortal, self::class);
    }

    /**
     * Gera vários boletos num único request (assíncrono). Devolve o ticket do lote.
     *
     * @param  list<ChargeInput>  $inputs
     */
    public function createBatch(array $inputs): string
    {
        $boletos = array_map($this->boletoPayload(...), $inputs);
        $response = $this->client->post("v2/boletos/gerar/boleto/lote/convenios/{$this->convenio}", $boletos);

        return $this->ticket($response);
    }

    /**
     * Resultado de um lote; null enquanto o AILOS ainda processa.
     *
     * @return array<string, mixed>|null
     */
    public function fetchBatch(string $ticket): ?array
    {
        try {
            return $this->client->get("v1/boletos/consultar/boleto/lote/convenios/{$this->convenio}/{$ticket}");
        } catch (AilosApiException $exception) {
            return $this->stillProcessing($exception);
        }
    }

    /** Pede o arquivo de retorno do dia (`Y-m-d`). Devolve o ticket. */
    public function requestReturnFile(string $movementDate): string
    {
        $response = $this->client->post("v1/boletos/solicitar/arquivo/retorno/convenios/{$this->convenio}/{$movementDate}");

        return $this->ticket($response);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listReturnFiles(string $fromDate, string $toDate): array
    {
        $response = $this->client->get("v1/boletos/listar/arquivo/retorno/convenios/{$this->convenio}/{$fromDate}/{$toDate}");

        return ApiPayload::toListOfArrays($response['arquivosRetorno'] ?? null);
    }

    /** Conteúdo do zip do arquivo de retorno; null enquanto o AILOS ainda processa. */
    public function downloadReturnFile(string $ticket): ?string
    {
        try {
            return $this->client->download("v1/boletos/baixar/arquivo/retorno/convenios/{$this->convenio}/{$ticket}");
        } catch (AilosApiException $exception) {
            $this->stillProcessing($exception);

            return null;
        }
    }

    /**
     * O boleto leva o pagador completo: busca o cadastro (customerExternalId = CPF/CNPJ).
     *
     * @return array<string, mixed>
     */
    private function boletoPayload(ChargeInput $input): array
    {
        GatewayRequirements::assertServes($this, DefaultGatewayContext::make(currency: $input->currency), $input->paymentMethod);

        return $this->mapper->boleto(
            $input,
            fn (string $document): array => $this->client->get("v1/pagadores/consultar/{$document}"),
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function ticket(array $response): string
    {
        $ticket = ApiPayload::optionalString($response['ticketLote'] ?? null);

        if ($ticket === null || $ticket === '') {
            throw new AilosApiException("Resposta do AILOS sem 'ticketLote'.", 502, $response);
        }

        return $ticket;
    }

    /** O AILOS responde 400 com "[99]" enquanto processa; qualquer outro erro sobe. */
    private function stillProcessing(AilosApiException $exception): null
    {
        if ($exception->getStatusCode() === 400 && str_contains($exception->getMessage(), self::PROCESSING_MARKER)) {
            return null;
        }

        throw $exception;
    }
}
