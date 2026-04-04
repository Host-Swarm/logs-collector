# Observability

This application exposes Docker state on demand, so its own observability must cover both infrastructure connectivity and request activity.

Track at minimum:

- Docker socket connectivity status
- Parent app (Passport) reachability for token validation
- Active log stream count (concurrent streaming responses)
- Active exec session count
- Authentication failures (server secret mismatches, token validation failures)
- Docker API errors (timeout, socket unavailable, 404 for unknown containers)
- Request count per endpoint

Expose health information through a safe internal health endpoint or artisan command.

Prefer structured logs with machine-readable context values.

## Structured Log Context

Every internal log entry should include as relevant:

- `container_id`
- `endpoint` (logs | exec | stacks)
- `auth_method` (server_secret | passport_token)
- `docker_error` (if applicable)
- `parent_app_status` (if a Passport validation call was made)

## Do Not Log

- Token values (not even partial)
- Server secret value
- Container exec output or log content
- Full Docker API response bodies
