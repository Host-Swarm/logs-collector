<?php

declare(strict_types=1);

use App\Domain\Docker\Contracts\ExecService;
use App\Http\Middleware\AccessTokenMiddleware;
use App\Infrastructure\Docker\DockerApiException;
use App\Infrastructure\Docker\DockerHttpClient;

beforeEach(fn () => $this->withoutMiddleware(AccessTokenMiddleware::class));

function fakeDockerClientForExec(int $statusCode): void
{
    $mock = Mockery::mock(DockerHttpClient::class);
    $mock->shouldReceive('getJson')->andThrow(new DockerApiException('error', $statusCode));

    app()->instance(DockerHttpClient::class, $mock);
}

// ---------- GET /api/containers/{id}/exec ----------

it('returns 400 when container ID format is invalid for exec', function (): void {
    $response = $this->get('/api/containers/not-a-valid-id/exec');

    $response->assertStatus(400);
    $response->assertJson(['error' => 'Invalid container ID.']);
});

it('returns 404 when container is not found for exec', function (): void {
    fakeDockerClientForExec(404);

    $response = $this->get('/api/containers/abc123def456/exec');

    $response->assertStatus(404);
    $response->assertJson(['error' => 'Container not found.']);
});

it('returns 503 when docker is unavailable for exec', function (): void {
    fakeDockerClientForExec(0);

    $response = $this->get('/api/containers/abc123def456/exec');

    $response->assertStatus(503);
    $response->assertJson(['error' => 'Docker unavailable.']);
});

it('returns 500 when exec create fails', function (): void {
    $dockerMock = Mockery::mock(DockerHttpClient::class);
    $dockerMock->shouldReceive('getJson')->andReturn([]);
    app()->instance(DockerHttpClient::class, $dockerMock);

    $execMock = Mockery::mock(ExecService::class);
    $execMock->shouldReceive('createExec')->andThrow(new RuntimeException('exec create failed'));
    app()->instance(ExecService::class, $execMock);

    $response = $this->get('/api/containers/abc123def456/exec');

    $response->assertStatus(500);
    $response->assertJson(['error' => 'Failed to create exec session.']);
});

it('returns 500 when exec start fails', function (): void {
    $dockerMock = Mockery::mock(DockerHttpClient::class);
    $dockerMock->shouldReceive('getJson')->andReturn([]);
    app()->instance(DockerHttpClient::class, $dockerMock);

    $execMock = Mockery::mock(ExecService::class);
    $execMock->shouldReceive('createExec')->andReturn('abc-exec-id');
    $execMock->shouldReceive('startExec')->andThrow(new RuntimeException('exec start failed'));
    app()->instance(ExecService::class, $execMock);

    $response = $this->get('/api/containers/abc123def456/exec');

    $response->assertStatus(500);
    $response->assertJson(['error' => 'Failed to start exec session.']);
});
