---
name: implement-stack-api
description: Implement or modify the stack listing and stack detail HTTP API endpoints in host-swarm-agent, including Docker Swarm discovery, DTO mapping, server secret authentication, and JSON response shaping.
---
# Purpose

Use this skill when the task involves adding or changing:

- `GET /api/stacks` — list all stacks
- `GET /api/stacks/{stack}` — stack detail with services and containers
- `ServerSecretMiddleware` — server secret Bearer token authentication
- `SwarmDiscoveryService` — Docker service/task/container discovery and stack grouping
- `StackDTO`, `ServiceDTO`, `ContainerDTO` — typed response shapes

# Repository assumptions

This is a stateless Laravel HTTP application. There is no database. Stack data is always fetched live from the Docker socket on each request.

# Required workflow

1. Identify the exact change requested.
2. Locate the affected layer:
   - Docker infrastructure (`DockerHttpClient`, `SwarmDiscoveryService`)
   - DTOs (`StackDTO`, `ServiceDTO`, `ContainerDTO`)
   - Controller (`StackController`)
   - Middleware (`ServerSecretMiddleware`)
3. Preserve architecture boundaries:
   - Docker calls stay in `App\Infrastructure\Docker`
   - Stack grouping and mapping stays in `App\Domain\Docker\Services\SwarmDiscoveryService`
   - Controllers stay thin — call the service, return the DTO as JSON
   - Auth logic stays in `ServerSecretMiddleware` only
4. Validate `{stack}` path parameter against discovered stack names — return 404 if not found.
5. Implement the smallest correct change.
6. Add structured logging for Docker errors.
7. Add tests:
   - happy path: stacks listed, stack detail returned
   - missing stack: 404
   - wrong server secret: 401
   - no secret provided: 401
   - Docker socket error: 503
8. Summarize: files changed, config changes, risks/follow-ups.

# Response shapes

### `GET /api/stacks`

```json
{
  "stacks": [
    { "name": "my-app", "services": 3 }
  ]
}
```

### `GET /api/stacks/{stack}`

```json
{
  "stack": "my-app",
  "services": [
    {
      "id": "svc-abc",
      "name": "my-app_web",
      "mode": "Replicated",
      "replicas": 2,
      "image": "nginx:latest",
      "containers": [
        {
          "id": "ctr-def",
          "name": "my-app_web.1.xyz",
          "state": "running",
          "node_id": "node-1",
          "node_hostname": "worker-1",
          "task_id": "task-001",
          "task_slot": 1
        }
      ]
    }
  ]
}
```

# Output expectations

Prefer:

- typed `StackDTO`, `ServiceDTO`, `ContainerDTO` returned from the domain service
- `JsonResource` or direct `response()->json()` in the controller
- `hash_equals()` in `ServerSecretMiddleware` for constant-time comparison

Avoid:

- fetching Docker data inside the controller
- returning raw Docker API response shapes
- exposing internal service labels or sensitive container metadata unless needed
- storing or caching discovery results (always fetch live)
