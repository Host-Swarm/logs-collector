<?php

declare(strict_types=1);

use App\Domain\Auth\Contracts\TokenValidator;

function fakeTokenValidator(bool $result): void
{
    $mock = Mockery::mock(TokenValidator::class);
    $mock->shouldReceive('validate')->andReturn($result);

    app()->instance(TokenValidator::class, $mock);
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
