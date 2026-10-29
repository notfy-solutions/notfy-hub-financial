<?php

declare(strict_types=1);

namespace Notfy\HubFinancial\Support;

use RuntimeException;

/**
 * Extração tipada de valores vindos de payloads JSON decodificados (mixed por
 * natureza), usada por todos os gateways/webhooks Asaas para satisfazer o
 * PHPStan nível 9 sem casts silenciosos sobre dados de terceiros.
 */
final class ApiPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function toAssocArray(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function toListOfArrays(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $list = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * @return list<string>
     */
    public static function toListOfStrings(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, is_string(...)));
    }

    public static function optionalString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    public static function stringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    /**
     * @throws RuntimeException quando o valor não é uma string não vazia
     */
    public static function requiredString(mixed $value, string $context): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Resposta da Asaas sem '{$context}' válido.");
        }

        return $value;
    }

    public static function toFloat(mixed $value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    public static function toInt(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
