<?php

declare(strict_types=1);

use App\Domain\Auth\Contracts\ScopedTokenValidator;
use App\Http\Middleware\AccessTokenMiddleware;
use App\Infrastructure\Docker\DockerApiException;
use App\Infrastructure\Docker\DockerHttpClient;
use Illuminate\Support\Facades\Http;

/**
 * Bind a mock validator that records calls to validate().
 *
 * @return array{mock: Mockery\MockInterface, calls: array<int, array{token: string, scope: string}>}
 */
function mockValidator(bool $returns = true): array
{
    $calls = [];
    $mock = Mockery::mock(ScopedTokenValidator::class);
    $mock->shouldReceive('validate')
        ->andReturnUsing(function (string $token, string $scope) use ($returns, &$calls): bool {
            $calls[] = ['token' => $token, 'scope' => $scope];

            return $returns;
        });

    app()->instance(ScopedTokenValidator::class, $mock);

    return ['mock' => $mock, 'calls' => &$calls];
}

function fakeDockerForLogs(): void
{
    $docker = Mockery::mock(DockerHttpClient::class);
    $docker->shouldReceive('openStreamSocket')
        ->andThrow(new DockerApiException('not found', 404));

    app()->instance(DockerHttpClient::class, $docker);
}

function fakeDiscoveryForAccessTest(): void
{
    $discovery = Mockery::mock(App\Domain\Docker\Services\SwarmDiscoveryService::class);
    $discovery->shouldReceive('discover')->andReturn([]);

    app()->instance(App\Domain\Docker\Services\SwarmDiscoveryService::class, $discovery);

    // StackService is a singleton — replace it so it uses the fresh mock
    $stackService = new App\Domain\Docker\Services\StackService(discovery: $discovery);
    app()->instance(App\Domain\Docker\Services\StackService::class, $stackService);
}

// ---------- Missing / blank token ----------

it('returns 401 when accessToken query param is missing', function (): void {
    $response = $this->getJson('/api/health');

    $response->assertUnauthorized();
    $response->assertJson(['error' => 'Unauthorized.']);
});

it('returns 401 when accessToken query param is empty', function (): void {
    $response = $this->getJson('/api/health?accessToken=');

    $response->assertUnauthorized();
    $response->assertJson(['error' => 'Unauthorized.']);
});

// ---------- Validation rejects ----------

it('returns 401 when the validator rejects the token', function (): void {
    mockValidator(false);

    $response = $this->getJson('/api/health?accessToken=bad-token');

    $response->assertUnauthorized();
    $response->assertJson(['error' => 'Unauthorized.']);
});

// ---------- Valid token passes through ----------

it('passes the request through when the validator accepts', function (): void {
    mockValidator(true);

    $response = $this->getJson('/api/health?accessToken=good-token');

    $response->assertSuccessful();
});

// ---------- Scope: global ----------

it('sends scope global for routes without a stack parameter', function (): void {
    $result = mockValidator(true);

    $this->getJson('/api/health?accessToken=my-token');

    expect($result['calls'])->toHaveCount(1)
        ->and($result['calls'][0]['token'])->toBe('my-token')
        ->and($result['calls'][0]['scope'])->toBe('global');
});

// ---------- Scope: stack name from route parameter ----------

it('sends scope with stack name for /stacks/{stack} route', function (): void {
    $result = mockValidator(true);
    fakeDiscoveryForAccessTest();

    $this->getJson('/api/stacks/my-stack?accessToken=my-token');

    expect($result['calls'])->toHaveCount(1)
        ->and($result['calls'][0]['scope'])->toBe('my-stack');
});

// ---------- Scope: stack name from query parameter ----------

it('sends scope with stack query param for container routes', function (): void {
    $result = mockValidator(true);
    fakeDockerForLogs();

    $this->getJson('/api/containers/abc123def456/logs?accessToken=my-token&stack=web-app');

    expect($result['calls'])->toHaveCount(1)
        ->and($result['calls'][0]['scope'])->toBe('web-app');
});

// ---------- Scope: global for container routes without stack ----------

it('sends scope global for container routes without stack query param', function (): void {
    $result = mockValidator(true);
    fakeDockerForLogs();

    $this->getJson('/api/containers/abc123def456/logs?accessToken=my-token');

    expect($result['calls'])->toHaveCount(1)
        ->and($result['calls'][0]['scope'])->toBe('global');
});

// ---------- Validator calls the correct external URL ----------

it('POSTs to SERVER_URL/api/logs/validate-token with token and scope', function (): void {
    config(['logs_collector.server_url' => 'http://server.test']);
    config(['logs_collector.parent_app.timeout' => 5]);

    Http::fake(['http://server.test/api/logs/validate-token' => Http::response(['valid' => true], 200)]);

    $this->withoutMiddleware(AccessTokenMiddleware::class);

    $validator = app(ScopedTokenValidator::class);
    $result = $validator->validate('my-token', 'global');

    expect($result)->toBeTrue();

    Http::assertSent(function (Illuminate\Http\Client\Request $request): bool {
        return $request->url() === 'http://server.test/api/logs/validate-token'
            && $request->data()['token'] === 'my-token'
            && $request->data()['scope'] === 'global';
    });
});

// ---------- URL-encoded access token is decoded correctly ----------

it('accepts a URL-encoded access token', function (): void {
    $result = mockValidator(true);

    $encoded = urlencode('tok+en/with=special&chars');
    $this->getJson("/api/health?accessToken={$encoded}");

    expect($result['calls'])->toHaveCount(1)
        ->and($result['calls'][0]['token'])->toBe('tok+en/with=special&chars');
});
