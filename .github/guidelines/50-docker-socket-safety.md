# Docker Socket Safety

Access to `docker.sock` is highly privileged.
Treat it as sensitive infrastructure access.

## Rules

- Grant the collector only the minimum operational behavior needed.
- Never expose docker socket operations through public HTTP endpoints.
- Never pass arbitrary user input directly into Docker API calls.
- Whitelist supported Docker actions.
- Read operations are preferred; avoid write or destructive Docker operations unless explicitly designed and reviewed.
- Sanitize and limit metadata collected from containers.

## Security posture

Assume compromise of this app could affect the host.
Therefore:

- keep the feature surface small
- avoid dynamic code execution
- avoid shelling out when a Docker API client is sufficient
- validate all identifiers before using them
