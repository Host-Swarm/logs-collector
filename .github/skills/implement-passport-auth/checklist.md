# Implement Passport Auth Checklist

- [ ]  `PassportOneTimeMiddleware` registered on log and exec routes
- [ ]  `ServerSecretMiddleware` registered on stack API routes
- [ ]  `PassportOneTimeMiddleware` extracts token from `Authorization: Bearer` header
- [ ]  `PassportOneTimeMiddleware` extracts container ID from route parameter
- [ ]  `PassportTokenValidator` makes outbound POST to `{PARENT_APP_URL}{PARENT_APP_TOKEN_VERIFY_PATH}`
- [ ]  Outbound validation request includes both `token` and `container_id`
- [ ]  Outbound HTTP call has an explicit timeout configured
- [ ]  Token validation result is NOT cached or stored
- [ ]  `ServerSecretMiddleware` uses `hash_equals()` — not `===`
- [ ]  Both middlewares return `401` on failure without revealing details
- [ ]  Auth failures logged with structured context (no token value logged)
- [ ]  Config keys defined: `app.server_secret`, `app.parent_app_url`, `app.parent_app_token_verify_path`
- [ ]  Tests: valid token, token for wrong container, expired token, parent app unreachable, missing header, wrong secret
