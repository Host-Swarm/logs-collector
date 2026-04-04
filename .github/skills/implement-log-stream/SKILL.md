---
name: implement-log-stream
description: Implement or modify the on-demand container log streaming HTTP endpoint in host-swarm-agent, including Docker log streaming, frame parsing, response chunking, and Passport one-time token authentication.
---
# Purpose

Use this skill when the task involves adding or changing how the agent:

- streams container logs via `GET /containers/{id}/logs`
- reads log frames from the Docker socket
- decodes Docker's multiplexed binary frame format
- returns a chunked HTTP streaming response to the caller
- validates or rejects Passport one-time tokens for log access

# Repository assumptions

This is a Laravel HTTP application that mounts `docker.sock` and serves authenticated on-demand streaming endpoints. There is no upstream WebSocket push — logs are pulled per request.

# Required workflow

1. Identify the exact change requested.
2. Locate the affected layer:
   - Docker infrastructure (`DockerLogStreamService`, `DockerLogFrameParser`, `DockerHttpClient`)
   - Domain service (`ContainerLogService`)
   - Controller (`ContainerLogsController`)
   - Middleware (`PassportOneTimeMiddleware`)
3. Preserve architecture boundaries:
   - Docker streaming logic stays in `App\Infrastructure\Docker`
   - Business orchestration belongs in `App\Domain\Docker\Services`
   - Controllers stay thin — extract params, delegate to service, return stream
   - Auth logic stays in middleware only
4. Validate the container ID format before the Docker call.
5. Implement the smallest correct change.
6. Add structured logging for errors (never log log content).
7. Add tests:
   - happy path (valid token, valid container ID, Docker returns log lines)
   - invalid/expired Passport token → 401
   - malformed container ID → 400
   - Docker socket error → 500
   - Docker returns 404 for unknown container → 404
8. Confirm `PassportOneTimeMiddleware` is on the route.
9. Summarize: files changed, config changes, risks/follow-ups.

# Output expectations

Prefer:

- chunked `StreamedResponse` for log output
- Docker frame parsing in infrastructure layer only
- typed query param DTOs (tail, stdout, stderr, timestamps)
- structured error logs with container_id context

Avoid:

- buffering all log output before sending
- log content appearing in application logs
- bypassing middleware for quick hacks
- raw env access in app code
