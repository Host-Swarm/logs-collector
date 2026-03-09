# Testing Strategy

Every feature should be testable without requiring a real swarm in the default test suite.

## Required test levels

### Unit tests

For:

- payload normalization
- parsing logic
- identifier mapping
- retry policy decisions
- deduplication logic

### Feature tests

For:

- websocket / upstream dispatch orchestration
- command startup behavior
- health endpoint or status command behavior
- failure and reconnect scenarios

### Integration tests

Use fakes or mocks for:

- Docker client
- upstream socket client

Do not make normal CI depend on a live docker socket.
Live integration tests can exist separately.

## Expectations

When adding a stream-related feature, test:

- happy path
- upstream unavailable
- docker unavailable
- malformed container metadata
- burst log input
