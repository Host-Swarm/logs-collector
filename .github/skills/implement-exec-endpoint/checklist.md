# Implement Exec Endpoint Checklist

- [ ]  `GET /containers/{id}/exec` route registered under `PassportOneTimeMiddleware`
- [ ]  Container ID validated (hex, 12–64 chars) before Docker call
- [ ]  `DockerExecService` encapsulates exec create and exec start calls
- [ ]  Exec command hardcoded to `["/bin/sh"]` — not caller-controlled
- [ ]  Exec options: `AttachStdin`, `AttachStdout`, `AttachStderr`, `Tty` all true
- [ ]  WebSocket upgrade handled; bytes proxied bidirectionally
- [ ]  WebSocket close triggers Docker exec stream close
- [ ]  Controller stays thin — no direct Docker calls
- [ ]  Auth stays in `PassportOneTimeMiddleware` only
- [ ]  Structured error logs added (no exec I/O content logged)
- [ ]  Tests: valid auth + container, bad token, bad container ID, Docker exec create error, container not found
- [ ]  No exec input/output in application logs
