# Implement Stack API Checklist

- [ ]  `GET /api/stacks` route registered under `ServerSecretMiddleware`
- [ ]  `GET /api/stacks/{stack}` route registered under `ServerSecretMiddleware`
- [ ]  `ServerSecretMiddleware` uses `hash_equals()` for constant-time comparison
- [ ]  `ServerSecretMiddleware` returns `401` without revealing secret details on failure
- [ ]  `SwarmDiscoveryService` groups services by `com.docker.stack.namespace` label
- [ ]  `StackDTO`, `ServiceDTO`, `ContainerDTO` defined with typed properties
- [ ]  Controller stays thin — no Docker logic, just calls service and returns JSON
- [ ]  `{stack}` path parameter validated — 404 if not found
- [ ]  Docker socket errors surfaced as `503` with structured log
- [ ]  Tests: list stacks, stack detail, missing stack, wrong secret, no secret, Docker error
- [ ]  No raw Docker API shapes in API response
- [ ]  `SERVER_SECRET` env documented in config
