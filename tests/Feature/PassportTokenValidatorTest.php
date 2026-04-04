<?php

declare(strict_types=1);

use App\Infrastructure\Auth\PassportTokenValidator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;

it('returns true when parent app confirms token is valid', function (): void {
    Http::fake([
        'http://server-manager/api/token/verify' => Http::response(['valid' => true], 200),
    ]);

    $validator = new PassportTokenValidator(
        parentAppUrl: 'http://server-manager',
        verifyPath: '/api/token/verify',
        timeoutSeconds: 5,
        logger: new NullLogger,
    );

    expect($validator->validate('my-token', 'abc123def456'))->toBeTrue();
});

it('sends token as Bearer and container_id in body', function (): void {
    Http::fake([
        'http://server-manager/api/token/verify' => Http::response([], 200),
    ]);

    $validator = new PassportTokenValidator(
        parentAppUrl: 'http://server-manager',
        verifyPath: '/api/token/verify',
        timeoutSeconds: 5,
        logger: new NullLogger,
    );

    $validator->validate('my-token', 'abc123def456');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'http://server-manager/api/token/verify'
            && $request->hasHeader('Authorization', 'Bearer my-token')
            && $request->data()['container_id'] === 'abc123def456';
    });
});

it('returns false when parent app rejects token', function (): void {
    Http::fake([
        'http://server-manager/api/token/verify' => Http::response(['error' => 'invalid'], 401),
    ]);

    $validator = new PassportTokenValidator(
        parentAppUrl: 'http://server-manager',
        verifyPath: '/api/token/verify',
        timeoutSeconds: 5,
        logger: new NullLogger,
    );

    expect($validator->validate('bad-token', 'abc123def456'))->toBeFalse();
});

it('returns false when parent app is unreachable', function (): void {
    Http::fake([
        'http://server-manager/api/token/verify' => Http::response(null, 500),
    ]);

    $validator = new PassportTokenValidator(
        parentAppUrl: 'http://server-manager',
        verifyPath: '/api/token/verify',
        timeoutSeconds: 5,
        logger: new NullLogger,
    );

    expect($validator->validate('some-token', 'abc123def456'))->toBeFalse();
});
