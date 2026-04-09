# Host Swarm Agent

Host Swarm Agent is a Laravel HTTP service that exposes Docker Swarm state and container operations to the **server-manager** application over a secure HTTP API.

The service mounts the Docker socket and provides authenticated endpoints for viewing container logs, executing interactive shell sessions, and inspecting swarm topology.

---

# Responsibilities

The agent is responsible for:

- Exposing Docker Swarm stack/service/container topology via authenticated HTTP API
- Streaming container logs on demand over chunked HTTP
- Providing interactive exec sessions into containers over chunked HTTP with FIFO-based input relay
- Authenticating all API requests via scoped access tokens validated against the parent server-manager

---

# Architecture Overview

```
Docker Swarm (docker.sock)
         │
         ▼
Host Swarm Agent (Laravel HTTP + nginx + PHP-FPM)
         │
         ├── GET  /api/stacks                              ?accessToken=...
         ├── GET  /api/stacks/{stack}                      ?accessToken=...
         ├── GET  /api/health                              ?accessToken=...
         │
         ├── GET  /api/containers/{id}/logs                ?accessToken=...
         ├── GET  /api/containers/{id}/stream              ?accessToken=...  (alias of /logs)
         ├── GET  /api/containers/{id}/exec                ?accessToken=...
         ├── POST /api/containers/exec/{session}/input     ?accessToken=...
         └── POST /api/containers/exec/{session}/resize    ?accessToken=...
                         │
                         ▼ (token validation on every request)
                   server-manager POST /api/logs/validate-token
```

---

# API Reference

All endpoints require a `?accessToken=<token>` query parameter. The token is validated against the parent server-manager on every request (see [Authentication](#authentication)).

## Stack APIs

### `GET /api/stacks`

Returns all stacks discovered from running Docker Swarm services and plain Docker Compose projects.

```json
{
  "stacks": [
    {
      "name": "my-app",
      "services": 3
    }
  ]
}
```

### `GET /api/stacks/{stack}`

Returns a single stack with its services and their running containers/tasks.

```json
{
  "stack": "my-app",
  "services": [
    {
      "id": "svc-abc123",
      "name": "my-app_web",
      "mode": "Replicated",
      "replicas": 2,
      "image": "nginx:latest",
      "containers": [
        {
          "id": "ctr-def456...",
          "name": "my-app_web.1.xyz",
          "state": "running",
          "node_id": "node-789",
          "node_hostname": "worker-1",
          "task_id": "task-000",
          "task_slot": 1,
          "image": "nginx:latest",
          "service_id": "svc-abc123"
        }
      ]
    }
  ]
}
```

`service_id` is set for Swarm services and `null` for plain Docker Compose containers.

### `GET /api/health`

Returns full stack tree with health status and log viewer URLs. Returns `503` with `{ "status": "degraded" }` if Docker is unreachable.

## Container Log Streaming

### `GET /api/containers/{containerId}/logs`
### `GET /api/containers/{containerId}/stream`

Both routes are equivalent. Streams raw Docker multiplexed log frames from the given container.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `stdout` | bool | `1` | Include stdout |
| `stderr` | bool | `1` | Include stderr |
| `follow` | bool | `1` | Keep stream open for new output |
| `timestamps` | bool | `0` | Prefix each line with RFC 3339 timestamp |
| `tail` | string | `100` | Number of lines from the end (`all` for everything) |
| `since` | string | — | Unix timestamp; only return logs after this time |
| `serviceId` | string | — | Swarm service ID for multi-node fallback (see below) |
| `stack` | string | — | Stack name for access token scope resolution |

**Response**: Chunked `application/octet-stream` with raw Docker multiplexed binary frames (8-byte header + payload per frame). The client is responsible for parsing the frame format.

**Multi-node fallback**: If the container is not found locally (returns Docker 404) and `serviceId` is provided, the agent transparently retries against Docker's `/services/{serviceId}/logs` endpoint, which the Swarm manager can serve for containers on any node.

**Error responses**: `400` invalid container ID, `404` container not found, `502` stream open failed, `503` Docker unavailable.

## Interactive Exec

### `GET /api/containers/{containerId}/exec`

Opens an interactive `/bin/sh` session inside the container. The shell command is hardcoded and not user-controllable.

**Response**: Chunked `application/octet-stream` stream. The first line is a JSON object with the session ID:

```json
{"session": "uuid-here"}
```

All subsequent bytes are raw TTY output from the container.

### `POST /api/containers/exec/{session}/input`

Sends terminal input to an active exec session. The raw request body is relayed to the container shell via a named pipe (FIFO).

**Response**: `204` on success, `404` session not found, `410` session expired.

### `POST /api/containers/exec/{session}/resize`

Resizes the exec TTY to match the client terminal dimensions.

```json
{ "cols": 120, "rows": 40 }
```

**Response**: `204` on success.

## Web UI

The agent also serves a built-in log viewer and exec terminal UI:

| Route | Description |
|---|---|
| `GET /logs` | All containers log viewer |
| `GET /logs/{stack}` | Stack-scoped log viewer |
| `GET /logs/{stack}/{service}` | Service-scoped log viewer |
| `GET /logs/{stack}/{service}/{containerId}` | Single container log viewer |
| `GET /exec/{containerId}` | Interactive terminal UI |

All web routes also require `?accessToken=<token>`.

---

# Authentication

All routes (except the internal `/up` health check) are protected by `AccessTokenMiddleware`. There is a single authentication mechanism: scoped access tokens validated against the parent server-manager.

## Token Flow

1. The parent **server-manager** issues a scoped access token for the user.
2. The client passes the token as `?accessToken=<token>` on every request.
3. The agent POSTs `{ token, scope }` to `{PARENT_APP_URL}/api/logs/validate-token`.
4. On a successful response, the request proceeds. On failure, `401 Unauthorized` is returned.
5. Tokens are never cached or stored by the agent.

## Scope Resolution

The scope sent to the parent app for validation depends on the route:

| Route pattern | Scope |
|---|---|
| `/api/stacks/{stack}*` | Stack name (from URL) |
| `/logs/{stack}*` | Stack name (from URL) |
| `/api/stacks` (index) | `global` |
| Container/exec routes | `?stack=<name>` query param, or `global` |

---

# Security Model

- Docker socket access is limited to **read operations** (logs, inspect, list) except for exec.
- Exec into containers uses a hardcoded `/bin/sh` shell — the command is not user-controllable.
- No user-supplied input is passed directly into Docker API paths without sanitization.
- Container IDs are validated against `/^[a-f0-9]{12,64}$/` before any Docker call.
- Exec session IDs are validated as UUID format before use.
- Token values never appear in application logs.
- This service has **no database** — all auth is stateless and ephemeral.

---

# Running the Agent

## Docker

```bash
docker run \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -e PARENT_APP_URL=https://server-manager.example.com \
  -e SERVER_ID=your-server-uuid \
  -e CONNECTION_KEY=your-connection-key \
  -p 80:80 \
  host-swarm-agent
```

## Environment

Required:

| Variable | Description |
|---|---|
| `PARENT_APP_URL` | Base URL of the parent server-manager app (for token validation) |
| `SERVER_ID` | UUID identifying this server in the parent app |
| `CONNECTION_KEY` | Connection key for the parent app |

Optional:

| Variable | Description | Default |
|---|---|---|
| `SERVER_URL` | Public URL of the parent server-manager | — |
| `SERVER_SECRET` | Shared secret (legacy, kept for compatibility) | — |
| `LOG_COLLECTOR_SWARM_KEY` | Logical name for this swarm | `main-swarm` |
| `HEARTBEAT_INTERVAL` | Heartbeat interval in seconds | `30` |
| `DOCKER_SOCKET_PATH` | Path to Docker socket | `/var/run/docker.sock` |
| `DOCKER_TIMEOUT` | General Docker API timeout (seconds) | `10` |
| `DOCKER_CONNECT_TIMEOUT` | Docker socket connect timeout (seconds) | `5` |
| `DOCKER_STREAM_TIMEOUT` | Stream read timeout — 0 means infinite | `0` |
| `PARENT_APP_TOKEN_VERIFY_PATH` | Token validation endpoint path | `/api/token/verify` |
| `PARENT_APP_TIMEOUT` | Token validation HTTP timeout (seconds) | `5` |

## Docker Compose

```yaml
services:
  agent:
    image: host-swarm-agent
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
    environment:
      PARENT_APP_URL: "${PARENT_APP_URL:-http://server-manager}"
      SERVER_ID: "${SERVER_ID}"
      CONNECTION_KEY: "${CONNECTION_KEY}"
      LOG_COLLECTOR_SWARM_KEY: "${LOG_COLLECTOR_SWARM_KEY:-main-swarm}"
    ports:
      - "80:80"
    networks:
      - server_manager
```

**Swarm note:** This agent must run on a Swarm manager node. Worker nodes return HTTP `503` for service discovery. For containers on remote nodes, the agent falls back to service-level log streaming automatically.

---

# Observability

The agent logs:

- Docker socket connectivity issues
- Authentication failures (scope and endpoint, never the token)
- Docker API errors with container/service context
- Discovery counts (services found, containers resolved)
- Stream lifecycle events (open, close)

---

# Development Goals

This service is designed to be:

- Stateless (no database required)
- Lightweight
- Safe with Docker socket access
- Straightforward to deploy alongside server-manager

---

# Related Projects

Host Swarm ecosystem:

- server-manager (parent Laravel app)
- deployment orchestrator
- monitoring services
- swarm automation tools
