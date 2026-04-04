<?php

declare(strict_types=1);

use App\Domain\Auth\Contracts\TokenValidator;

function fakeExecTokenValidator(bool $result): void
{
    $mock = Mockery::mock(TokenValidator::class);
    $mock->shouldReceive('validate')->andReturn($result);

    app()->instance(TokenValidator::class, $mock);
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
