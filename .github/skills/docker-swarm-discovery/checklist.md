# Docker Swarm Discovery Checklist

- [ ]  Services fetched and grouped into stacks by `com.docker.stack.namespace` label
- [ ]  Tasks fetched per service and filtered to running state
- [ ]  Containers resolved per running task
- [ ]  `StackDTO`, `ServiceDTO`, `ContainerDTO` typed and stable
- [ ]  Docker infrastructure isolated in `App\Infrastructure\Docker`
- [ ]  Domain service handles mapping/grouping in `App\Domain\Docker\Services`
- [ ]  Partial results returned when container resolve fails (no full pipeline crash)
- [ ]  No raw Docker response shapes exposed directly via API
- [ ]  Structured logs emitted for discovery counts and failures
- [ ]  Tests: happy path, empty swarm, missing task container, Docker socket failure
- [ ]  No destructive Docker action introduced
- [ ]  No raw user input passed into Docker API calls
