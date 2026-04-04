# Docker Socket Safety

Access to `docker.sock` is highly privileged.
Treat it as sensitive infrastructure access.

## Allowed Operations

The following Docker API operations are explicitly allowed:

| Operation | Docker API | Purpose |
|---|---|---|
| List services | `GET /services` | Discover swarm topology |
| List tasks | `GET /tasks?filters=...` | Resolve service tasks |
| Inspect container | `GET /containers/{id}/json` | Get container metadata |
| Stream logs | `GET /containers/{id}/logs` | On-demand log streaming |
| Create exec instance | `POST /containers/{id}/exec` | Exec session setup |
| Start exec instance | `POST /exec/{id}/start` | Exec session execution |

All other Docker API calls are **prohibited** unless explicitly reviewed and added to this list.

## Rules

- Never expose raw Docker socket operations through unauthenticated HTTP endpoints.
- Always validate container IDs before passing them to Docker API calls (hex format, 12–64 chars).
- Never pass arbitrary user input directly into Docker API query strings or paths.
- Sanitize and limit metadata collected from containers.
- Avoid shelling out — use the Docker HTTP-over-socket client for all Docker operations.
- Exec sessions must only be opened for requests that have passed `PassportOneTimeMiddleware` validation.

## Exec Safety

The exec endpoint introduces write-capable Docker access.
Apply extra caution:

- Validate the container ID from the URL parameter before the exec call.
- Only create exec instances with `AttachStdin`, `AttachStdout`, `AttachStderr`, and `Tty` as true.
- Do not allow callers to specify the command to execute — the command must be hardcoded to an interactive shell (`["/bin/sh"]` or `["/bin/bash"]`).
- Enforce authentication — exec must always go through `PassportOneTimeMiddleware`.

## Security Posture

Assume compromise of this application could affect the host.

Therefore:

- keep the feature surface small
- avoid dynamic code execution
- validate all identifiers before use
- log authentication failures (without leaking the token value)
- never log container exec output or log content to the application log
