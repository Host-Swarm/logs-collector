---
name: implement-exec-endpoint
description: Implement or modify the interactive container exec (SSH-like) endpoint in host-swarm-agent, including Docker exec API calls, WebSocket upgrade handling, Passport one-time token authentication, and exec safety constraints.
---
# Purpose

Use this skill when the task involves adding or changing:

- `GET /containers/{id}/exec` — interactive exec session (WebSocket)
- `DockerExecService` — creates and starts Docker exec instances
- `ContainerExecController` — thin controller managing the WebSocket upgrade
- `PassportOneTimeMiddleware` — one-time Passport token validation for this route

# Repository assumptions

This is a stateless Laravel HTTP application. There is no database. Exec sessions are ephemeral — once the WebSocket closes, the session is gone. The exec command is always hardcoded to an interactive shell; callers cannot specify a custom command.

# Required workflow

1. Identify the exact change requested.
2. Locate the affected layer:
   - Docker infrastructure (`DockerExecService`, `DockerHttpClient`)
   - Controller (`ContainerExecController`)
   - Middleware (`PassportOneTimeMiddleware`)
3. Preserve architecture boundaries:
   - Docker exec API calls stay in `App\Infrastructure\Docker\DockerExecService`
   - Controller stays thin — validate container ID, delegate exec setup to service, upgrade to WebSocket
   - Auth stays in `PassportOneTimeMiddleware` only
4. Container ID must be validated (hex, 12–64 chars) before any Docker call.
5. The exec command must be hardcoded: `["/bin/sh"]` (or `["/bin/bash"]` if sh is unavailable). Never let the caller specify the command.
6. Implement the smallest correct change.
7. Add structured logging for errors (never log exec output or input).
8. Add tests:
   - valid token and container ID: exec instance created and WebSocket upgraded
   - invalid/expired token: 401
   - malformed container ID: 400
   - Docker socket error on exec create: 500
   - container not found: 404
9. Summarize: files changed, config changes, risks/follow-ups.

# Docker exec flow

```
POST /containers/{id}/exec
  body: { "AttachStdin": true, "AttachStdout": true, "AttachStderr": true, "Tty": true, "Cmd": ["/bin/sh"] }
  → response: { "Id": "<exec-id>" }

POST /exec/<exec-id>/start
  body: { "Detach": false, "Tty": true }
  → hijacks the connection to a raw stream (stdin/stdout forwarded over WebSocket)
```

# WebSocket handling

- Upgrade the HTTP connection to a WebSocket.
- Proxy bytes bidirectionally: WebSocket frames ↔ Docker exec stream.
- On WebSocket close, close the Docker exec stream.
- On Docker exec stream close, close the WebSocket with a normal close frame.

# Security constraints

- Exec command is always `["/bin/sh"]` — never caller-controlled.
- Only open an exec instance after `PassportOneTimeMiddleware` has confirmed the token is valid for the requested container ID.
- Do not log exec input or output content.
- Do not allow multiple concurrent exec sessions per token — one token, one session.

# Output expectations

Prefer:

- `DockerExecService` encapsulating both the exec create and exec start Docker calls
- WebSocket upgrade handled at the HTTP layer (not inside the domain service)
- structured error logs with `container_id` context

Avoid:

- letting the controller make direct Docker API calls
- allowing caller-specified exec commands
- logging exec I/O content
- storing any exec session state
