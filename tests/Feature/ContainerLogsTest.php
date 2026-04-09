<?php

declare(strict_types=1);

use App\Http\Middleware\AccessTokenMiddleware;
use App\Infrastructure\Docker\DockerApiException;
use App\Infrastructure\Docker\DockerHttpClient;

beforeEach(fn () => $this->withoutMiddleware(AccessTokenMiddleware::class));

function fakeDockerClientForLogs(int $statusCode): void
{
    $mock = Mockery::mock(DockerHttpClient::class);
    $mock->shouldReceive('openStreamSocket')->andThrow(new DockerApiException('error', $statusCode));

    app()->instance(DockerHttpClient::class, $mock);
}

// ---------- GET /containers/{id}/logs ----------

it('returns 400 when container ID format is invalid', function (): void {
    $response = $this->get('/api/containers/not-a-valid-id/logs');

    $response->assertStatus(400);
    $response->assertJson(['error' => 'Invalid container ID.']);
});

it('returns 404 when container is not found', function (): void {
    fakeDockerClientForLogs(404);

    $response = $this->get('/api/containers/abc123def456/logs');

    $response->assertStatus(404);
    $response->assertJson(['error' => 'Container not found.']);
});

it('returns 503 when docker is unavailable for logs', function (): void {
    fakeDockerClientForLogs(0);

    $response = $this->get('/api/containers/abc123def456/logs');

    $response->assertStatus(503);
    $response->assertJson(['error' => 'Docker unavailable.']);
});
