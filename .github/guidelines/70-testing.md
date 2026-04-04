# Testing Strategy

Every feature must be testable without requiring a live Docker socket or a live parent app in the default test suite.

## Required Test Levels

### Unit Tests

For:

- Container ID format validation
- Docker response parsing and DTO mapping
- Stack/service hierarchy assembly from raw Docker API responses
- Log frame parsing (multiplexed Docker stream decoding)
- Server secret constant-time comparison logic

### Feature Tests

For:

- `GET /api/stacks` — authenticated, unauthenticated, empty swarm
- `GET /api/stacks/{stack}` — found, not found, Docker error
- `GET /containers/{id}/logs` — valid token, invalid token, bad container ID format, Docker error
- `GET /containers/{id}/exec` — valid token, invalid token, bad container ID format
- `PassportOneTimeMiddleware` — successful validation, parent app returns 4xx/5xx, network timeout

### Integration Tests

Use fakes or mocks for:

- `DockerHttpClient` — return fixed JSON payloads or simulate timeouts
- `PassportTokenValidator` — return success or failure without hitting the parent app

Do not make CI depend on a live Docker socket or a live parent app.

## Expectations

When adding a new endpoint or middleware, test:

- happy path (correct auth, valid container)
- wrong credentials (wrong server secret / invalid Passport token)
- bad input (malformed container ID, out-of-range query params)
- infrastructure failure (Docker socket error, parent app unreachable)
- partial data (container exists but metadata incomplete)
