# HTTP API Rules

This app serves Docker Swarm state and container operations over HTTP. There is no upstream WebSocket push — all data flows are request-driven.

## Authentication

### Server secret (stack/service endpoints)

- Route group: `/api/stacks`, `/api/stacks/{stack}`
- Middleware: `ServerSecretMiddleware`
- Mechanism: compare `Authorization: Bearer <value>` header against `config('app.server_secret')` using `hash_equals()` for constant-time comparison.
- On failure: return `401 Unauthorized` with a JSON error body. Never reveal whether the secret exists.

### Passport one-time token (log/exec endpoints)

- Route group: `/containers/{id}/logs`, `/containers/{id}/exec`
- Middleware: `PassportOneTimeMiddleware`
- Mechanism:
  1. Extract the Bearer token from the `Authorization` header.
  2. Make an outbound HTTP POST to `{PARENT_APP_URL}{PARENT_APP_TOKEN_VERIFY_PATH}` with the token and the requested container ID.
  3. If the parent app confirms the token is valid and scoped for that container, allow the request.
  4. Never store the token or the validation result.
  5. Each request must re-validate — do not cache between requests.
- On failure: return `401 Unauthorized`. Do not reveal validation details.

### Token scope contract

The parent app is expected to embed the container ID in the token so it can verify:
- token is valid (not expired, not revoked)
- token was issued for the requested container ID

This contract is owned by the parent app. The agent just sends the token + container ID and trusts the parent app's response.

## Response shapes

Use consistent typed response shapes.

- All JSON responses must include an outer wrapper key (e.g. `stacks`, `stack`).
- All error responses must use the shape `{"error": "message"}` with an appropriate HTTP status.
- Log stream responses use `Content-Type: text/plain; charset=utf-8` with chunked transfer encoding.
- Exec sessions use WebSocket upgrade.

## Container ID validation

Before passing any container ID to the Docker API:

- Validate format: must match `/^[a-f0-9]{12,64}$/` (Docker short or full ID).
- Return `400 Bad Request` if the format does not match.
- Never pass raw path parameters directly into Docker API URLs.

## Request parameters

Log stream endpoint accepts:
- `tail` — integer, default `100`, max `10000`
- `stdout` — boolean, default `1`
- `stderr` — boolean, default `1`
- `timestamps` — boolean, default `0`

Validate and clamp all parameters. Reject unexpected types with `400`.

## No sessions

This application has no database and no session store.

- Do not use Laravel's session middleware on any route.
- Do not issue cookies.
- Every request is fully independent.
