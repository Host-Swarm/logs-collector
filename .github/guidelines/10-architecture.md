# Architecture Rules

Follow these boundaries strictly.

## Request flow

```
HTTP Request
     │
     ▼
Middleware (ServerSecretMiddleware | PassportOneTimeMiddleware)
     │
     ▼
Controller (thin — route dispatch only)
     │
     ▼
Domain Service (orchestration, business logic)
     │
     ▼
Infrastructure Layer (Docker HTTP client, Auth HTTP client)
     │
     ▼
HTTP Response (JSON | Streamed log | WebSocket exec)
```

## Layers

Use clear Laravel-oriented layers:

- `App\Domain\Docker\DTOs` — typed response shapes for stacks, services, containers
- `App\Domain\Docker\Services` — orchestration: SwarmDiscoveryService, ContainerLogService, ContainerExecService
- `App\Domain\Auth\Contracts` — interface for token validation
- `App\Infrastructure\Docker` — raw Docker socket communication (DockerHttpClient, DockerLogStreamService, DockerExecService, DockerLogFrameParser)
- `App\Infrastructure\Auth` — PassportTokenValidator (HTTP call to parent app)
- `App\Http\Controllers` — thin; reads request params, calls services, returns responses
- `App\Http\Middleware` — ServerSecretMiddleware, PassportOneTimeMiddleware
- `App\Console\Commands` — only for maintenance or diagnostics; no HTTP-equivalent logic here

## Route groups

```
/api/stacks          → ServerSecretMiddleware
/api/stacks/{stack}  → ServerSecretMiddleware
/containers/{id}/logs → PassportOneTimeMiddleware
/containers/{id}/exec → PassportOneTimeMiddleware
```

## State

This application is **stateless** — no database, no sessions, no persistent token store.

- Stack/service API: authenticate on each request via constant-time secret comparison.
- Log/exec API: validate the Passport token on each request via HTTP call to parent app. Never cache or reuse the validation result.

## Controllers

Controllers must remain thin:
- Extract and validate the request parameter (container ID format, query params).
- Delegate to domain services.
- Return the typed response or initiate the stream.
- Never place Docker logic, auth logic, or retry logic inside controllers.

## No broadcasting

This app does **not** broadcast logs to Pusher/Soketi or any WebSocket upstream.
Broadcasting infrastructure (PusherLogBroadcaster, LogBroadcaster contract) does not belong in this codebase.
Log streaming is done by responding to HTTP requests with a chunked/streaming response.
