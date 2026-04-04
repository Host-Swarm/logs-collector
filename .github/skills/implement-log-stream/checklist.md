# Checklist

- [ ]  Route registered under `PassportOneTimeMiddleware`
- [ ]  Container ID format validated before Docker call
- [ ]  Docker log streaming isolated in infrastructure layer
- [ ]  Log frame parser handles both TTY and non-TTY containers
- [ ]  Chunked `StreamedResponse` used (not buffered)
- [ ]  Query params validated and clamped (tail, stdout, stderr, timestamps)
- [ ]  Structured error logs added (never log log content)
- [ ]  Tests: happy path, bad token, bad container ID, Docker error
- [ ]  No token or secret values in logs
