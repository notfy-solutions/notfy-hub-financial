<?php

declare(strict_types=1);

use Notfy\HubFinancial\Support\ApiPayload;

it('converts a decoded payload to an associative array', function () {
    expect(ApiPayload::toAssocArray(['a' => 1]))->toBe(['a' => 1])
        ->and(ApiPayload::toAssocArray('not-an-array'))->toBe([])
        ->and(ApiPayload::toAssocArray(null))->toBe([]);
});

it('converts a decoded payload to a list of arrays, dropping non-array items', function () {
    expect(ApiPayload::toListOfArrays([['a' => 1], 'not-an-array', ['b' => 2]]))->toBe([['a' => 1], ['b' => 2]])
        ->and(ApiPayload::toListOfArrays('not-an-array'))->toBe([])
        ->and(ApiPayload::toListOfArrays(null))->toBe([]);
});

it('extracts an optional string', function () {
    expect(ApiPayload::optionalString('value'))->toBe('value')
        ->and(ApiPayload::optionalString(123))->toBeNull()
        ->and(ApiPayload::optionalString(null))->toBeNull();
});

it('extracts a string or falls back to the default', function () {
    expect(ApiPayload::stringOrDefault('value', 'default'))->toBe('value')
        ->and(ApiPayload::stringOrDefault(123, 'default'))->toBe('default')
        ->and(ApiPayload::stringOrDefault(null, 'default'))->toBe('default');
});

it('extracts a required string', function () {
    expect(ApiPayload::requiredString('value', 'field'))->toBe('value');
});

it('throws when the required string is missing, empty or not a string', function () {
    expect(fn () => ApiPayload::requiredString(null, 'id'))->toThrow(RuntimeException::class, "Resposta da Asaas sem 'id' válido.")
        ->and(fn () => ApiPayload::requiredString('', 'id'))->toThrow(RuntimeException::class)
        ->and(fn () => ApiPayload::requiredString(123, 'id'))->toThrow(RuntimeException::class);
});

it('extracts a float or falls back to the default', function () {
    expect(ApiPayload::toFloat('19.9'))->toBe(19.9)
        ->and(ApiPayload::toFloat(19.9))->toBe(19.9)
        ->and(ApiPayload::toFloat('not-numeric', 5.0))->toBe(5.0)
        ->and(ApiPayload::toFloat(null))->toBe(0.0);
});

it('extracts an int or falls back to the default', function () {
    expect(ApiPayload::toInt('10'))->toBe(10)
        ->and(ApiPayload::toInt(10))->toBe(10)
        ->and(ApiPayload::toInt('not-numeric', 3))->toBe(3)
        ->and(ApiPayload::toInt(null))->toBe(0);
});
