<?php

declare(strict_types=1);

use Notfy\HubFinancial\Exceptions\StripeApiException;

it('exposes the status code and payload of a failed request', function () {
    $exception = new StripeApiException('No such customer', 404, ['error' => ['message' => 'No such customer']]);

    expect($exception->getMessage())->toBe('No such customer')
        ->and($exception->getStatusCode())->toBe(404)
        ->and($exception->getPayload())->toBe(['error' => ['message' => 'No such customer']]);
});

it('defaults the status code and payload when not provided', function () {
    $exception = new StripeApiException('Generic error');

    expect($exception->getStatusCode())->toBe(0)
        ->and($exception->getPayload())->toBe([]);
});
