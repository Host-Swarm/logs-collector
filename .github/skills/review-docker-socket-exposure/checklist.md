# Docker Socket Exposure Review Checklist

- [ ]  All Docker-touching code paths identified and classified
- [ ]  Every Docker endpoint is behind appropriate authentication middleware
- [ ]  Container IDs validated (hex, 12–64 chars) before all Docker API calls
- [ ]  No raw user input passed directly into Docker API paths or query strings
- [ ]  Docker timeouts configured for all operations
- [ ]  Exec endpoint: command is hardcoded, not caller-controlled
- [ ]  Exec endpoint: behind `PassportOneTimeMiddleware`
- [ ]  `ServerSecretMiddleware` uses `hash_equals()` for constant-time comparison
- [ ]  `PassportOneTimeMiddleware` never caches or reuses token validation
- [ ]  Both middlewares return `401` without leaking details on failure
- [ ]  No secrets, tokens, or sensitive metadata in application logs
- [ ]  Docker socket mount scope is minimal (not privileged mode)
- [ ]  Findings documented by severity with recommended mitigations
