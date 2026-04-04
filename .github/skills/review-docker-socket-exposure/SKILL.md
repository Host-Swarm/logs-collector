---
name: review-docker-socket-exposure
description: Review host-swarm-agent for security and operational risks related to mounting and using Docker socket access, including the exec endpoint and authenticated HTTP routes.
---
# Purpose

Use this skill when reviewing the safety of the agent architecture, especially around `docker.sock` and the authenticated endpoints that use it.

# Review workflow

1. Identify all code paths that touch Docker.
2. Classify each as:
   - discovery (list services, tasks)
   - read-only metadata access (container inspect)
   - log streaming
   - exec (write-capable — highest risk)
3. For each Docker-touching code path, verify:
   - Is it behind appropriate authentication middleware?
   - Is the container ID validated (hex format, 12–64 chars) before the Docker call?
   - Is user-supplied input sanitized before reaching Docker API paths?
   - Are timeouts configured?
   - Are errors handled without leaking Docker internals to the caller?
4. For the exec endpoint specifically, verify:
   - It is behind `PassportOneTimeMiddleware`
   - The exec command is hardcoded (not caller-controlled)
   - Exec instance and exec start calls use only safe options
5. Review container/runtime assumptions:
   - Docker socket mount scope
   - Network exposure (only the needed port exposed)
   - Secrets in environment variables not leaking into logs
6. Review auth middleware:
   - `ServerSecretMiddleware` uses `hash_equals()` for constant-time comparison
   - `PassportOneTimeMiddleware` never caches or reuses token validation results
   - Both middlewares return `401` without revealing secret details on failure
7. Recommend the least-invasive hardening improvements.

# Output format

Return:

- risk summary
- findings by severity (Critical / High / Medium / Low)
- exact files/areas to review
- recommended mitigations
- quick wins vs later improvements

# Rules

Flag any path that:
- accepts raw user input into Docker calls
- shells out unnecessarily
- exposes Docker actions via unauthenticated routes
- leaks secrets, tokens, or sensitive metadata in logs
- has no timeouts, limits, or input validation
- allows the caller to control exec command arguments

Prefer practical, grounded mitigations.
Avoid generic security advice not applicable to this repository.
