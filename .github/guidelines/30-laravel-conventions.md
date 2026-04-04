# Laravel Conventions

Write idiomatic Laravel code.
Follow standard Laravel practices.
Use constructor dependency injection.
Avoid static service access where possible.
All configuration must be defined inside config files.
Never call env() outside config.
Use DTOs instead of raw arrays for API payloads.
Controllers must remain lightweight.
Use structured logging with context values.

## General

- Prefer constructor injection.
- Prefer small services and actions over massive helper classes.
- Use config files for all connection settings.
- Use environment variables only through config.
- Validate all external payloads before use.
- Use typed properties, return types, and purpose-driven DTOs.

## Configuration

Create dedicated config files such as:

- `config/logs_collector.php` — all app-specific settings

Do not scatter `env()` calls across app code.

## HTTP Responses

- Return typed JSON responses from controllers.
- Streaming responses use `response()->stream()` with `Content-Type: text/plain; charset=utf-8`.
- Error responses always use `{"error": "message"}` with an appropriate HTTP status code.
- Never expose exception stack traces in API responses.

## Error Handling

Catch infrastructure exceptions at the boundary layer.
Convert them into explicit domain-safe exceptions or structured result objects.
Controllers catch `Throwable` at the top level and map to HTTP status codes.

## Logging

Use structured logs.
Every internal log context should include as relevant:

- `container_id`
- `endpoint` (logs | exec | stacks)
- `auth_method` (server_secret | passport_token)
- `error` (message only — never stack trace in structured context)

## No Queues, No Broadcasting

This application does not use queued jobs or broadcasting.
Do not add `ShouldQueue`, event listeners, or Pusher-related code.
