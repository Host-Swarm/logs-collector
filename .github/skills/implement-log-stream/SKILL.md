---
name: implement-log-stream
description: Implement or modify the Docker Swarm service-container-log streaming pipeline in host-swarm-logs-collector, including discovery, normalization, forwarding, retries, tests, and safety checks.
---
# Purpose

Use this skill when the task involves adding or changing how the collector:

- discovers swarm services or containers
- reads logs from Docker
- normalizes log payloads
- forwards log events to the main server-manager socket
- handles reconnect, buffering, or retries

# Repository assumptions

This repository is a Laravel application that mounts `docker.sock` and forwards normalized log events upstream.

# Required workflow

1. Identify the exact change requested.
2. Locate the affected layer:
   - Docker infrastructure
   - log normalization
   - upstream socket transport
   - queue / retry / buffering
   - health / diagnostics
3. Preserve architecture boundaries:
   - infrastructure code stays in infrastructure
   - normalization belongs in domain services / DTOs
   - controllers stay thin
4. Define or update the payload contract first.
5. Implement the smallest correct change.
6. Add structured logging for the new behavior.
7. Add tests:
   - happy path
   - upstream failure or reconnect path
   - malformed or partial container metadata if relevant
8. Confirm no unsafe public exposure of docker socket behavior.
9. Summarize:
   - files changed
   - payload changes
   - config changes
   - risks / follow-ups

# Output expectations

Prefer:

- typed DTOs
- small services / actions
- config-driven endpoints and timeouts
- explicit retry/backoff logic
- test coverage

Avoid:

- putting transport logic in controllers
- raw env access in app code
- ad hoc payload arrays scattered across files
- shell-based Docker interaction if an API client abstraction exists
