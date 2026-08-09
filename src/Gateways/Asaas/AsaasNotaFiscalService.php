<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Asaas;

use Notfy\HubFinancial\Contracts\NotaFiscalGateway;
use Notfy\HubFinancial\DTO\NotaFiscalInput;
use Notfy\HubFinancial\DTO\NotaFiscalResult;
use Notfy\HubFinancial\Exceptions\AsaasApiException;

/**
 * Emissão de Nota Fiscal (NFS-e) nativa do Asaas. A configuração fiscal
 * municipal (serviço, alíquotas padrão, certificado) é feita diretamente
 * no dashboard do Asaas — este cliente não lida com certificado A1.
 */
final class AsaasNotaFiscalService implements NotaFiscalGateway
{
    public function __construct(
        private readonly AsaasHttpClient $client,
    ) {}

    public function emit(NotaFiscalInput $input): NotaFiscalResult
    {
        $payload = $this->filter([
            'payment' => $input->paymentExternalId,
            'installment' => $input->installmentExternalId,
            'customer' => $input->customerExternalId,
            'serviceDescription' => $input->serviceDescription,
            'observations' => $input->observations,
            'externalReference' => $input->externalReference,
            'value' => round($input->serviceValueCents / 100, 2),
            'deductions' => $input->deductions,
            'effectiveDate' => $input->effectiveDate,
            'municipalServiceId' => $input->municipalServiceId,
            'municipalServiceCode' => $input->municipalServiceCode,
            'municipalServiceName' => $input->municipalServiceName,
            'updatePayment' => $input->updatePayment,
            'taxes' => $input->taxes->toArray(),
        ]);

        $invoice = $this->client->post('invoices', $payload);

        return $this->toResult($invoice);
    }

    public function getStatus(string $externalId): NotaFiscalResult
    {
        $invoice = $this->client->get("invoices/{$externalId}");

        return $this->toResult($invoice);
    }

    public function cancel(string $externalId): bool
    {
        try {
            $this->client->post("invoices/{$externalId}/cancel");

            return true;
        } catch (AsaasApiException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function toResult(array $invoice): NotaFiscalResult
    {
        $status = (string) ($invoice['status'] ?? 'ERROR');

        return new NotaFiscalResult(
            externalId: (string) ($invoice['id'] ?? ''),
            status: $status,
            number: $invoice['number'] ?? null,
            validationCode: $invoice['validationCode'] ?? null,
            pdfUrl: $invoice['pdfUrl'] ?? null,
            xmlUrl: $invoice['xmlUrl'] ?? null,
            errorMessage: $status === 'ERROR' ? ($invoice['errors'][0]['description'] ?? $invoice['errorMessage'] ?? null) : null,
            raw: $invoice,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function filter(array $data): array
    {
        return array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
