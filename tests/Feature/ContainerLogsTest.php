<?php

declare(strict_types=1);

use App\Domain\Auth\Contracts\TokenValidator;
use App\Infrastructure\Docker\DockerApiException;
use App\Infrastructure\Docker\DockerHttpClient;

function fakeTokenValidator(bool $result): void
{
    $mock = Mockery::mock(TokenValidator::class);
    $mock->shouldReceive('validate')->andReturn($result);

    app()->instance(TokenValidator::class, $mock);
}

function fakeDockerClientForLogs(int $statusCode): void
{
    $mock = Mockery::mock(DockerHttpClient::class);
    $mock->shouldReceive('getJson')->andThrow(new DockerApiException('error', $statusCode));

    app()->instance(DockerHttpClient::class, $mock);
}

// ---------- GET /containers/{id}/logs ----------

it('returns 401 when no token is provided', function (): void {
    $response = $this->get('/api/containers/abc123def456/logs');

    $response->assertStatus(401);
});

it('returns 400 when container ID format is invalid', function (): void {
    fakeTokenValidator(true);

    $response = $this->withHeaders(['Authorization' => 'Bearer some-token'])
        ->get('/api/containers/not-a-valid-id/logs');

    $response->assertStatus(400);
    $response->assertJson(['error' => 'Invalid container ID.']);
});

it('returns 401 when token validation fails', function (): void {
    fakeTokenValidator(false);

    $response = $this->withHeaders(['Authorization' => 'Bearer bad-token'])
        ->get('/api/containers/abc123def456/logs');

    $response->assertStatus(401);
});

it('returns 404 when container is not found', function (): void {
    fakeTokenValidator(true);
    fakeDockerClientForLogs(404);

    $response = $this->withHeaders(['Authorization' => 'Bearer valid-token'])
        ->get('/api/containers/abc123def456/logs');

    $response->assertStatus(404);
    $response->assertJson(['error' => 'Container not found.']);
});

it('returns 503 when docker is unavailable for logs', function (): void {
    fakeTokenValidator(true);
    fakeDockerClientForLogs(0);

    $response = $this->withHeaders(['Authorization' => 'Bearer valid-token'])
        ->get('/api/containers/abc123def456/logs');

    $response->assertStatus(503);
    $response->assertJson(['error' => 'Docker unavailable.']);
});
