<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

use InvalidArgumentException;

/**
 * Valor monetário em unidade menor da moeda (ISO 4217), ciente do expoente:
 * BRL/USD/EUR = 2 casas, JPY/KRW = 0, KWD/BHD = 3.
 */
final readonly class Money
{
    /** @var list<string> */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /** @var list<string> */
    private const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    public string $currency;

    public function __construct(public int $minorUnits, string $currency)
    {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Moeda inválida '{$currency}': use código ISO 4217 (3 letras).");
        }

        $this->currency = $currency;
    }

    public static function fromDecimal(string|int|float $amount, string $currency): self
    {
        $exponent = self::exponentFor($currency);

        return new self((int) round((float) $amount * 10 ** $exponent), $currency);
    }

    public static function exponentFor(string $currency): int
    {
        $currency = strtoupper($currency);

        return match (true) {
            in_array($currency, self::ZERO_DECIMAL, true) => 0,
            in_array($currency, self::THREE_DECIMAL, true) => 3,
            default => 2,
        };
    }

    public function exponent(): int
    {
        return self::exponentFor($this->currency);
    }

    public function toDecimal(): string
    {
        $exponent = $this->exponent();

        return number_format($this->minorUnits / 10 ** $exponent, $exponent, '.', '');
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits && $this->currency === $other->currency;
    }
}
