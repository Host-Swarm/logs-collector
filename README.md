# Host Swarm Agent

Host Swarm Agent is a Laravel HTTP service that exposes Docker Swarm state and container operations to the **server-manager** application over a secure HTTP API.

The service mounts the Docker socket and provides authenticated endpoints for viewing container logs, executing interactive shell sessions, and inspecting swarm topology.

---

# Responsibilities

The agent is responsible for:

- Exposing Docker Swarm stack/service/container topology via authenticated HTTP API
- Streaming container logs on demand over HTTP
- Providing interactive exec sessions into containers over HTTP
- Authenticating API requests using a server secret (stack/service APIs)
- Validating one-time Passport tokens from the parent server-manager (log/exec endpoints)

---

# Architecture Overview

```
Docker Swarm (docker.sock)
         │
         ▼
Host Swarm Agent (Laravel HTTP)
         │
         ├── GET  /api/stacks                  Bearer: SERVER_SECRET
         ├── GET  /api/stacks/{stack}           Bearer: SERVER_SECRET
         │
         ├── GET  /containers/{id}/logs         Passport one-time token (from server-manager)
         └── GET  /containers/{id}/exec         Passport one-time token (from server-manager)
                         │
                         ▼ (token validation)
                   server-manager (Laravel + Passport)
```

---

# API Reference

## Stack APIs — authenticated with `SERVER_SECRET`

### `GET /api/stacks`

Returns all stacks discovered from running Docker Swarm services.

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
          "id": "ctr-def456",
          "name": "my-app_web.1.xyz",
          "state": "running",
          "node_id": "node-789",
          "node_hostname": "worker-1",
          "task_id": "task-000",
          "task_slot": 1
        }
      ]
    }
  ]
}
```

## Container Endpoints — authenticated with one-time Passport token

### `GET /containers/{containerId}/logs`

Streams logs from the given container. Accepts `?tail=100&stdout=1&stderr=1`.

The response is a chunked HTTP stream of log lines with their stream type prefix.

### `GET /containers/{containerId}/exec`

Opens an interactive exec session (WebSocket upgrade). Allows the caller to run commands inside the container.

---

# Authentication

## Server Secret (Stack APIs)

Set `SERVER_SECRET` in your environment. Clients must send:

```
Authorization: Bearer <SERVER_SECRET>
```

This is a simple constant-time comparison — no tokens, no sessions, no DB.

## Passport One-Time Tokens (Log / Exec Endpoints)

The parent **server-manager** app (running Laravel + Passport) issues a one-time token scoped to a specific container ID. The flow:

1. The user requests log/exec access in server-manager.
2. server-manager issues a short-lived Passport token embedding the container ID as a scope claim.
3. The client sends the token to this agent.
4. This agent validates the token against server-manager's `/api/token/verify` endpoint (passing the container ID for scope check).
5. On success, access is granted. The token is never stored.
6. Each access requires a fresh token — sessions are never reused.

---

# Security Model

- Docker socket access is limited to **read operations** (logs, inspect, list).
- Exec into containers **is allowed** but only for authenticated, token-scoped requests.
- No user-supplied input is passed directly into Docker API paths without sanitization.
- Container IDs are validated (hex format, length) before use.
- Secrets never appear in application logs.
- This service has **no database** — all auth is stateless and ephemeral.

---

# Running the Agent

## Docker

```bash
docker run \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -e SERVER_SECRET=your-secret-here \
  -e PARENT_APP_URL=https://server-manager.example.com \
  -p 8080:8080 \
  host-swarm-agent
```

## Environment

Required:

| Variable | Description |
|---|---|
| `SERVER_SECRET` | Shared secret for authenticating stack API requests |
| `PARENT_APP_URL` | Base URL of the parent server-manager Laravel app |

Optional:

| Variable | Description | Default |
|---|---|---|
| `PARENT_APP_TOKEN_VERIFY_PATH` | Path on parent app to verify Passport tokens | `/api/token/verify` |
| `DOCKER_SOCKET_PATH` | Path to Docker socket | `/var/run/docker.sock` |
| `DOCKER_TIMEOUT` | General Docker API timeout (seconds) | `10` |
| `DOCKER_CONNECT_TIMEOUT` | Docker socket connect timeout (seconds) | `5` |
| `DOCKER_STREAM_TIMEOUT` | Log stream timeout — 0 means infinite | `0` |
| `LOG_COLLECTOR_SWARM_KEY` | Logical name for this swarm | `main-swarm` |

## Docker Compose

```yaml
services:
  agent:
    image: host-swarm-agent
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
    environment:
      SERVER_SECRET: "${SERVER_SECRET}"
      PARENT_APP_URL: "http://server-manager"
    ports:
      - "8080:8080"
    networks:
      - server_manager
```

Swarm note: this agent must run on a Swarm manager node. Worker nodes return HTTP 503 for `/services`.

---

# Observability

The agent exposes visibility for:

- Docker socket connectivity
- Active request counts (log streams, exec sessions)
- Authentication failures (without leaking tokens)
- Docker API errors

---

# Development Goals

This service is designed to be:

- stateless (no database required)
- lightweight
- safe with Docker socket access
- straightforward to deploy alongside server-manager

---

# Related Projects

Host Swarm ecosystem:

- server-manager (parent Laravel + Passport app)
- deployment orchestrator
- monitoring services
- swarm automation tools
