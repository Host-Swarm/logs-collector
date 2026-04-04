---
name: implement-passport-auth
description: Implement or modify the Passport one-time token authentication middleware in host-swarm-agent, including outbound token validation against the parent server-manager app, stateless single-use enforcement, and container ID scope checking.
---
# Purpose

Use this skill when the task involves adding or changing:

- `PassportOneTimeMiddleware` — validates one-time Passport tokens issued by server-manager
- `PassportTokenValidator` — infrastructure class making outbound HTTP call to parent app
- Token scope contract — container ID embedded in token, verified per request
- `ServerSecretMiddleware` — server secret Bearer token comparison for stack APIs

# Repository assumptions

This is a stateless Laravel HTTP application with no database. Auth is entirely ephemeral:
- Server secret comparison is a plain `hash_equals()` in-memory check.
- Passport token validation is an outbound HTTP call to the parent app on every request. Nothing is cached or stored.

# Token flow

```
1. server-manager issues a short-lived Passport token scoped to container ID X.
2. Client sends: Authorization: Bearer <token>  to  GET /containers/X/logs (or /exec)
3. PassportOneTimeMiddleware extracts the token and the container ID from the URL.
4. PassportTokenValidator sends: POST {PARENT_APP_URL}{PARENT_APP_TOKEN_VERIFY_PATH}
      body: { "token": "<token>", "container_id": "X" }
5. Parent app checks: token valid? token scoped for container X? → 200 OK or 4xx.
6. On 200: allow the request through. On anything else: return 401.
7. Token is NOT stored anywhere. Next request must re-validate.
```

# Architecture expectations

- `App\Http\Middleware\PassportOneTimeMiddleware` — extracts token + container ID, calls `PassportTokenValidator`, allows or aborts.
- `App\Http\Middleware\ServerSecretMiddleware` — extracts Bearer token, compares with `config('app.server_secret')` using `hash_equals()`, allows or aborts.
- `App\Infrastructure\Auth\PassportTokenValidator` — makes the outbound HTTP POST to parent app. Returns bool or throws on network failure.
- `App\Domain\Auth\Contracts\TokenValidator` — interface, implemented by `PassportTokenValidator`.

Keep auth logic inside middleware and infrastructure.
Controllers must not perform token validation.
Domain services must not know about tokens.

# Required workflow

1. Identify the exact change requested.
2. Locate the affected layer: middleware, infrastructure auth, config.
3. Implement the smallest correct change.
4. Add structured logging for auth failures (never log the token value).
5. Add tests:
   - valid token for the correct container → request passes through
   - valid token for a different container → 401
   - expired/revoked token (parent app returns 4xx) → 401
   - parent app unreachable (network error) → 503 or 401 (document the choice)
   - missing Authorization header → 401
   - malformed Bearer format → 401
   - wrong server secret for stack API → 401
6. Summarize: files changed, config changes, risks/follow-ups.

# Config keys

| Key | Env | Description |
|---|---|---|
| `app.server_secret` | `SERVER_SECRET` | Shared secret for stack API auth |
| `app.parent_app_url` | `PARENT_APP_URL` | Base URL of server-manager |
| `app.parent_app_token_verify_path` | `PARENT_APP_TOKEN_VERIFY_PATH` | Path on parent app for token verify (default: `/api/token/verify`) |

# Output expectations

Prefer:

- `hash_equals()` for server secret comparison (never `===`)
- outbound HTTP via Laravel's `Http` facade in `PassportTokenValidator`
- explicit timeouts on the outbound validation call
- 401 response on any auth failure — do not reveal why

Avoid:

- caching token validation results between requests
- logging the token value at any log level
- letting the controller inspect or decode the token
- using the same token for more than one request (the parent app enforces this; document the expectation)
