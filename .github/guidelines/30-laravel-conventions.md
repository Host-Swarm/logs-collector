# Laravel Conventions

Write idiomatic Laravel code.
Follow standard Laravel practices.
Use constructor dependency injection.
Avoid static service access where possible.
All configuration must be defined inside config files.
Never call env() outside config.
Use DTOs instead of raw arrays for log payloads.
Queue long running tasks.
Controllers must remain lightweight.
Use structured logging with context values.

## General

- Prefer constructor injection.
- Prefer small services and actions over massive helper classes.
- Use config files for all connection settings.
- Use environment variables only through config.
- Validate all external payloads before use.
- Use typed properties, return types, and purpose-driven DTOs.

## Configuration

Create dedicated config files such as:

- `config/docker.php`
- `config/log_collector.php`
- `config/upstream_socket.php`

Do not scatter `env()` calls across app code.

## Queues and jobs

Use queued jobs for:

- reconnect attempts
- deferred replay
- background service discovery
- backoff handling

Jobs must be idempotent where possible.

## Error handling

Catch infrastructure exceptions at the boundary layer.
Convert them into explicit domain-safe exceptions or structured result objects.

## Logging

Use structured logs.
Every internal log context should include as relevant:

- swarm_id or swarm_key
- service name or service id
- container id
- stream id
- upstream connection state

