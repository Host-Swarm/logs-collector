<?php

declare(strict_types=1);

use App\Domain\Auth\Contracts\TokenValidator;
use App\Infrastructure\Docker\DockerApiException;
use App\Infrastructure\Docker\DockerHttpClient;

function fakeExecTokenValidator(bool $result): void
{
    $mock = Mockery::mock(TokenValidator::class);
    $mock->shouldReceive('validate')->andReturn($result);

    app()->instance(TokenValidator::class, $mock);
}

function fakeDockerClientForExec(int $statusCode): void
{
    $mock = Mockery::mock(DockerHttpClient::class);
    $mock->shouldReceive('getJson')->andThrow(new DockerApiException('error', $statusCode));

    app()->instance(DockerHttpClient::class, $mock);
}

// ---------- GET /api/containers/{id}/exec ----------

it('returns 401 when no token is provided for exec', function (): void {
    $response = $this->get('/api/containers/abc123def456/exec');

    $response->assertStatus(401);
});

it('returns 400 when container ID format is invalid for exec', function (): void {
    fakeExecTokenValidator(true);

    $response = $this->withHeaders(['Authorization' => 'Bearer some-token'])
        ->get('/api/containers/not-a-valid-id/exec');

    $response->assertStatus(400);
    $response->assertJson(['error' => 'Invalid container ID.']);
});

it('returns 401 when token validation fails for exec', function (): void {
    fakeExecTokenValidator(false);

    $response = $this->withHeaders(['Authorization' => 'Bearer bad-token'])
        ->get('/api/containers/abc123def456/exec');

    $response->assertStatus(401);
});

it('returns 426 when WebSocket upgrade headers are missing', function (): void {
    fakeExecTokenValidator(true);

    $response = $this->withHeaders(['Authorization' => 'Bearer valid-token'])
        ->get('/api/containers/abc123def456/exec');

    $response->assertStatus(426);
    $response->assertJson(['error' => 'WebSocket upgrade required.']);
});

it('returns 404 when container is not found for exec', function (): void {
    fakeExecTokenValidator(true);
    fakeDockerClientForExec(404);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer valid-token',
        'Upgrade' => 'websocket',
        'Connection' => 'Upgrade',
        'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
    ])->get('/api/containers/abc123def456/exec');

    $response->assertStatus(404);
    $response->assertJson(['error' => 'Container not found.']);
});

it('returns 503 when docker is unavailable for exec', function (): void {
    fakeExecTokenValidator(true);
    fakeDockerClientForExec(0);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer valid-token',
        'Upgrade' => 'websocket',
        'Connection' => 'Upgrade',
        'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
    ])->get('/api/containers/abc123def456/exec');

    $response->assertStatus(503);
    $response->assertJson(['error' => 'Docker unavailable.']);
});
