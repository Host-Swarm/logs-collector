---
name: debug-log-forwarding
description: Diagnose why host-swarm-agent is returning errors, failing authentication, or not streaming container logs or exec sessions correctly.
---
# Purpose

Use this skill when an API endpoint is misbehaving: returning unexpected status codes, failing authentication, producing garbled log output, or failing to connect to the Docker socket.

# Debug workflow

Work through the pipeline in order:

1. Authentication layer

   - confirm the correct middleware is on the route
   - for server secret: verify `SERVER_SECRET` env is set and the client is sending it correctly
   - for Passport token: confirm `PARENT_APP_URL` is reachable from this container
   - confirm the parent app's `/api/token/verify` endpoint is responding
   - check if the token includes the expected container ID scope

2. Container ID validation

   - confirm the container ID in the URL is a valid hex string (12–64 chars)
   - confirm the container exists in Docker (run `docker inspect <id>`)

3. Docker access

   - confirm the Docker socket is mounted at `DOCKER_SOCKET_PATH`
   - confirm the agent can reach `/services`, `/tasks`, and `/containers/{id}/json`
   - check Docker API timeout configuration

4. Log streaming

   - verify `DockerLogStreamService` is sending correct query params (stdout, stderr, follow, tail)
   - confirm Docker log frame parsing handles both TTY and non-TTY containers
   - check for premature stream closure or buffering issues in the HTTP response

5. Exec session

   - confirm exec create and exec start calls are succeeding
   - confirm the WebSocket upgrade is being handled correctly

# Required deliverable

Return findings under:

- probable root cause
- evidence (log output, config values, test results)
- minimal fix
- tests to add

# Important rules

Do not assume authentication is the problem first — trace from the route through middleware, Docker, and response.
Never log or expose token values or the server secret in diagnostic output.
