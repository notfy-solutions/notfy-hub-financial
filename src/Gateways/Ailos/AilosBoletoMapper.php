<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Gateways\Ailos;

use Closure;
use InvalidArgumentException;
use Notfy\HubFinancial\Domain\Enums\ChargeStatus;
use Notfy\HubFinancial\Domain\Enums\NextActionType;
use Notfy\HubFinancial\DTO\ChargeInput;
use Notfy\HubFinancial\DTO\ChargeResult;
use Notfy\HubFinancial\DTO\CustomerInput;
use Notfy\HubFinancial\DTO\NextAction;
use Notfy\HubFinancial\Exceptions\AilosApiException;
use Notfy\HubFinancial\Support\ApiPayload;

/**
 * Traduz os DTOs agnósticos para o JSON da API de Cobrança do AILOS (e de
 * volta), respeitando tamanhos e enums do manual. Não faz I/O.
 */
final readonly class AilosBoletoMapper
{
    public function __construct(private int $carteira = 1) {}

    /**
     * @param  Closure(string): array<string, mixed>  $loadPayer  Recebe o CPF/CNPJ e devolve a resposta de
     *                                                            `v1/pagadores/consultar/{doc}`; só roda se o input for válido.
     * @return array<string, mixed>
     */
    public function boleto(ChargeInput $input, Closure $loadPayer): array
    {
        $dueDate = $input->dueDate ?? throw new InvalidArgumentException('O boleto AILOS exige data de vencimento.');
        $number = $this->boletoNumber($input);
        $payer = $this->chargePayer($input, $loadPayer($this->digits($input->customerExternalId)));

        $instructions = array_merge(
            ['tipoDesconto' => 3, 'tipoMulta' => 3, 'tipoJurosMora' => 3],
            ApiPayload::toAssocArray($input->extra['instrucoes'] ?? null),
        );

        return array_filter([
            'convenioCobranca' => ['codigoCarteiraCobranca' => $this->carteira],
            'documento' => [
                'numeroDocumento' => $number,
                'descricaoDocumento' => $this->limit($input->externalReference, 15),
                'especieDocumento' => ApiPayload::toInt($input->extra['especie_documento'] ?? null, 1),
            ],
            'emissao' => [
                'formaEmissao' => ApiPayload::toInt($input->extra['forma_emissao'] ?? null, 2),
                'dataEmissaoDocumento' => gmdate('Y-m-d'),
            ],
            'pagador' => $payer,
            'vencimento' => ['dataVencimento' => $dueDate],
            'instrucoes' => $instructions,
            'valorBoleto' => ['valorNominal' => round($input->amountCents / 100, 2)],
            'indicadorRegistroNuclea' => $input->extra['indicador_registro_nuclea'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function payer(CustomerInput $input): array
    {
        $document = $this->digits($input->document);

        $required = [
            'document' => $document,
            'postalCode' => $this->digits($input->postalCode ?? ''),
            'addressLine' => trim($input->addressLine ?? ''),
            'neighborhood' => trim($input->neighborhood ?? ''),
            'city' => trim($input->city ?? ''),
            'state' => trim($input->state ?? ''),
        ];

        $missing = array_search('', $required, true);
        if ($missing !== false) {
            throw new InvalidArgumentException("O pagador AILOS exige '{$missing}'.");
        }

        $phone = $this->digits($input->phone ?? '');

        return array_filter([
            'entidadeLegal' => [
                'identificadorReceitaFederal' => $document,
                'tipoPessoa' => $this->personType($document),
                'nome' => $this->limit($input->name, 50),
            ],
            'telefone' => strlen($phone) >= 10 ? [
                'ddi' => '55',
                'ddd' => substr($phone, 0, 2),
                'numero' => substr($phone, 2),
            ] : null,
            'emails' => $input->email !== null && $input->email !== '' ? [['endereco' => $input->email]] : null,
            'endereco' => array_filter([
                'cep' => $required['postalCode'],
                'logradouro' => $this->limit($required['addressLine'], 56),
                'numero' => $input->addressNumber,
                'bairro' => $this->limit($required['neighborhood'], 30),
                'cidade' => $this->limit($required['city'], 30),
                'uf' => strtoupper($required['state']),
            ], static fn (?string $value): bool => $value !== null && $value !== ''),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function chargeResult(array $response, string $fallbackNumber): ChargeResult
    {
        $boleto = ApiPayload::toAssocArray($response['boleto'] ?? $response);
        $document = ApiPayload::toAssocArray($boleto['documento'] ?? null);
        $number = $document['numeroDocumento'] ?? null;
        $barcode = ApiPayload::toAssocArray($boleto['codigoBarras'] ?? null);

        $nextAction = $barcode === [] ? null : new NextAction(NextActionType::Voucher, null, [
            'linha_digitavel' => ApiPayload::optionalString($barcode['linhaDigitavel'] ?? null),
            'codigo_barras' => ApiPayload::optionalString($barcode['codigoBarras'] ?? null),
            'nosso_numero' => ApiPayload::optionalString($document['nossoNumero'] ?? null),
        ]);

        return new ChargeResult(
            externalId: is_numeric($number) ? (string) (int) $number : $fallbackNumber,
            status: $this->status($boleto),
            raw: $response,
            nextAction: $nextAction,
        );
    }

    public function boletoNumber(ChargeInput $input): int
    {
        $number = $input->extra['numero_boleto'] ?? $input->externalReference;
        $number = is_int($number) ? (string) $number : $number;

        if (! is_string($number) || preg_match('/^\d{1,9}$/', $number) !== 1 || (int) $number === 0) {
            throw new InvalidArgumentException(
                "O boleto AILOS exige um sequencial numérico de até 9 dígitos em extra['numero_boleto'] (ou externalReference numérico).",
            );
        }

        return (int) $number;
    }

    public function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    /**
     * O boleto leva o pagador completo, vindo do cadastro (customerExternalId = CPF/CNPJ).
     *
     * @param  array<string, mixed>  $payerResponse
     * @return array<string, mixed>
     */
    private function chargePayer(ChargeInput $input, array $payerResponse): array
    {
        $document = $this->digits($input->customerExternalId);
        $payer = ApiPayload::toListOfArrays($payerResponse['pagadorResponse'] ?? null)[0]
            ?? throw new AilosApiException("Pagador {$document} não cadastrado no AILOS.", 404, $payerResponse);

        $legal = ApiPayload::toAssocArray($payer['entidadeLegal'] ?? null);
        $address = ApiPayload::toAssocArray($payer['endereco'] ?? null);
        $city = $address['cidade'] ?? null;
        $cityData = ApiPayload::toAssocArray($city);
        $messages = array_map(
            fn (string $message): string => $this->limit($message, 40),
            ApiPayload::toListOfStrings($input->extra['mensagens'] ?? $payer['mensagemPagador'] ?? null),
        );

        return array_filter([
            'entidadeLegal' => [
                'identificadorReceitaFederal' => $document,
                'tipoPessoa' => $this->personType($document),
                'nome' => $this->limit(ApiPayload::stringOrDefault($legal['nome'] ?? null, ''), 50),
            ],
            'telefone' => $this->nonEmpty(ApiPayload::toAssocArray($payer['telefone'] ?? null)),
            'emails' => $this->nonEmpty(ApiPayload::toListOfArrays($payer['emails'] ?? null)),
            'endereco' => [
                'cep' => $this->digits(ApiPayload::stringOrDefault($address['cep'] ?? null, '')),
                'logradouro' => $this->limit(ApiPayload::stringOrDefault($address['logradouro'] ?? null, ''), 56),
                'numero' => (string) ApiPayload::toInt($address['numero'] ?? null),
                'complemento' => $this->limit(ApiPayload::stringOrDefault($address['complemento'] ?? null, ''), 40),
                'bairro' => $this->limit(ApiPayload::stringOrDefault($address['bairro'] ?? null, ''), 30),
                'cidade' => $this->limit(ApiPayload::stringOrDefault($cityData['nome'] ?? $city, ''), 30),
                'uf' => ApiPayload::stringOrDefault($cityData['uf'] ?? null, ''),
            ],
            'mensagemPagador' => $this->nonEmpty($messages),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $boleto
     */
    private function status(array $boleto): ChargeStatus
    {
        return match (ApiPayload::toInt($boleto['indicadorSituacaoBoleto'] ?? null)) {
            5 => ChargeStatus::Received,
            3 => ChargeStatus::Deleted,
            default => $this->isPastDue($boleto) ? ChargeStatus::Overdue : ChargeStatus::Pending,
        };
    }

    /**
     * @param  array<string, mixed>  $boleto
     */
    private function isPastDue(array $boleto): bool
    {
        $due = ApiPayload::optionalString(ApiPayload::toAssocArray($boleto['vencimento'] ?? null)['dataVencimentoAtual'] ?? null);

        return $due !== null && substr($due, 0, 10) < gmdate('Y-m-d');
    }

    private function personType(string $document): int
    {
        return strlen($document) > 11 ? 2 : 1;
    }

    private function limit(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>|null
     */
    private function nonEmpty(array $value): ?array
    {
        return $value === [] ? null : $value;
    }
}
