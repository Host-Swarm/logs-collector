# Architecture Rules

Follow these boundaries:

## Core flow

1. Connect to Docker through the mounted Docker socket.
2. Resolve swarm services and running containers.
3. Subscribe to or tail container logs.
4. Convert raw log lines into a normalized event payload.
5. Push events to the main `server-manager` socket connection.
6. Retry safely when Docker or websocket connectivity is interrupted.

## Layers

Use clear Laravel-oriented layers:

- `App\Domain\Logs\DTOs` for transport-safe structured payloads
- `App\Domain\Logs\Actions` for single-purpose business actions
- `App\Domain\Logs\Services` for orchestration logic
- `App\Infrastructure\Docker` for Docker socket communication
- `App\Infrastructure\Sockets` for outbound websocket/socket communication
- `App\Console\Commands` only for startup, maintenance, or manual replay commands
- `App\Jobs` for queued or reconnect/recovery tasks
- `App\Events` only for internal app events, not as the primary external transport model

Controllers should remain thin.
Do not place Docker traversal or websocket retry logic inside controllers.

## State

Prefer stateless processing.
Persist only what is necessary for:

- stream checkpoints
- reconnect metadata
- rate limiting / deduplication
- health and diagnostics

Do not store full raw logs indefinitely unless explicitly required.
