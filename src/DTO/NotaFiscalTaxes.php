<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\DTO;

final class NotaFiscalTaxes
{
    public function __construct(
        public readonly bool $retainIss = false,
        public readonly float $iss = 0.0,
        public readonly float $pis = 0.0,
        public readonly float $cofins = 0.0,
        public readonly float $csll = 0.0,
        public readonly float $inss = 0.0,
        public readonly float $ir = 0.0,
    ) {}

    /**
     * @return array<string, bool|float>
     */
    public function toArray(): array
    {
        return [
            'retainIss' => $this->retainIss,
            'iss' => $this->iss,
            'pis' => $this->pis,
            'cofins' => $this->cofins,
            'csll' => $this->csll,
            'inss' => $this->inss,
            'ir' => $this->ir,
        ];
    }
}
