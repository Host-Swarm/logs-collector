<?php

declare(strict_types=1);

use App\Infrastructure\Docker\DockerApiException;
use App\Infrastructure\Docker\DockerHttpClient;

function fakeDockerClientForExec(int $statusCode): void
{
    $mock = Mockery::mock(DockerHttpClient::class);
    $mock->shouldReceive('getJson')->andThrow(new DockerApiException('error', $statusCode));

    app()->instance(DockerHttpClient::class, $mock);
}

// ---------- GET /api/containers/{id}/exec ----------

it('returns 400 when container ID format is invalid for exec', function (): void {
    $response = $this->withHeaders([
        'Upgrade' => 'websocket',
        'Connection' => 'Upgrade',
        'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
    ])->get('/api/containers/not-a-valid-id/exec');

    $response->assertStatus(400);
    $response->assertJson(['error' => 'Invalid container ID.']);
});

it('returns 426 when WebSocket upgrade headers are missing', function (): void {
    $response = $this->get('/api/containers/abc123def456/exec');

    $response->assertStatus(426);
    $response->assertJson(['error' => 'WebSocket upgrade required.']);
});

it('returns 404 when container is not found for exec', function (): void {
    fakeDockerClientForExec(404);

    $response = $this->withHeaders([
        'Upgrade' => 'websocket',
        'Connection' => 'Upgrade',
        'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
    ])->get('/api/containers/abc123def456/exec');

    $response->assertStatus(404);
    $response->assertJson(['error' => 'Container not found.']);
});

it('returns 503 when docker is unavailable for exec', function (): void {
    fakeDockerClientForExec(0);

    $response = $this->withHeaders([
        'Upgrade' => 'websocket',
        'Connection' => 'Upgrade',
        'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
    ])->get('/api/containers/abc123def456/exec');

    $response->assertStatus(503);
    $response->assertJson(['error' => 'Docker unavailable.']);
});
