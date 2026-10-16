<?php

declare(strict_types=1);

use Notfy\HubFinancial\Support\Money;

it('respects the ISO 4217 exponent', function (string $currency, int $exponent, int $minor, string $decimal) {
    $money = new Money($minor, $currency);

    expect($money->exponent())->toBe($exponent)
        ->and($money->toDecimal())->toBe($decimal)
        ->and(Money::fromDecimal($decimal, strtolower($currency))->equals($money))->toBeTrue();
})->with([
    ['BRL', 2, 1050, '10.50'],
    ['JPY', 0, 1000, '1000'],
    ['KWD', 3, 12345, '12.345'],
]);

it('rejects invalid currency codes', function () {
    new Money(100, 'REAL');
})->throws(InvalidArgumentException::class);

it('equals() compares amount and currency', function () {
    expect((new Money(1, 'USD'))->equals(new Money(1, 'EUR')))->toBeFalse();
});
