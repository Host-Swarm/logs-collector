---
name: docker-swarm-discovery
description: Discover Docker Swarm stacks, services, tasks, and running containers safely inside host-swarm-agent, then map them into a stable hierarchy for the stack API endpoints.
---

# Purpose

Use this skill when the task involves any of the following inside `host-swarm-agent`:

- discovering Docker Swarm services and grouping them into stacks
- resolving tasks for a service
- mapping tasks to running containers
- collecting container metadata for the stack detail API
- building or repairing the hierarchy:

  swarm > stack > service > task > container

- handling discovery drift caused by rolling deploys, restarts, scaling, or task replacement

This skill is for **read-oriented swarm discovery**. It must not introduce destructive Docker actions.

# Success criteria

A correct implementation:

1. reads swarm/service/task/container state from Docker through the infrastructure layer
2. groups services by stack (via `com.docker.stack.namespace` label)
3. produces a stable and normalized representation of stacks, services, and containers
4. avoids leaking Docker internals into controllers or request handlers
5. tolerates missing or stale task/container mappings gracefully
6. exposes enough metadata for the stack API without over-collecting sensitive data
7. provides structured logs and tests for discovery success and failure paths

# Architecture expectations

Keep these responsibilities separated:

- `App\Infrastructure\Docker\...`
  - raw Docker API access
  - listing services (`GET /services`)
  - listing tasks (`GET /tasks?filters=...`)
  - inspecting containers (`GET /containers/{id}/json`)
  - translating API responses into infrastructure DTOs or arrays

- `App\Domain\Docker\Services\SwarmDiscoveryService`
  - stack grouping (group services by `com.docker.stack.namespace` label)
  - service/task/container mapping and normalization
  - deciding which data is included in the API response

- `App\Domain\Docker\DTOs\...`
  - typed records: `StackDTO`, `ServiceDTO`, `ContainerDTO`

Do not place Docker discovery logic in controllers, commands, or middleware.

# Discovery workflow

1. Confirm Docker connectivity through the mounted socket.
2. List all Swarm services (`GET /services`).
3. Group services by `com.docker.stack.namespace` label value to form stacks.
4. For each service, list service tasks (`GET /tasks?filters={"service":["SERVICE_ID"]}`).
5. Filter tasks to `running` state where relevant.
6. Resolve the concrete container backing each running task (`GET /containers/{id}/json`).
7. Normalize results into `StackDTO → ServiceDTO → ContainerDTO` hierarchy.
8. Emit structured internal logs about discovery counts and failures.

# Data contract expectations

At minimum, a normalized `ContainerDTO` should expose:

- container ID
- container name
- container state
- node ID / node hostname
- task ID
- task slot
- image name

A `ServiceDTO` should expose:

- service ID
- service name
- service mode (Replicated / Global)
- replica count (for replicated services)
- image name

A `StackDTO` should expose:

- stack name
- service count

# Safety rules

- Never expose raw Docker discovery responses directly as API responses.
- Always validate container IDs before Docker inspect calls.
- Collect only the metadata needed for the API — do not forward full label maps unless explicitly required.
- Return partial results when a task's container cannot be resolved rather than failing the whole request.

# Failure handling

- Docker socket unavailable → return `503 Service Unavailable` from the controller
- Docker API timeout → return `504 Gateway Timeout`
- Service exists but tasks are empty → return service with empty containers array
- Task exists but container cannot be resolved → omit that container, log a warning
- Duplicate or stale task mappings → take the most recent running task per slot

# Anti-patterns

- Mixing Docker discovery with auth logic
- Returning ad hoc nested arrays from controllers
- Assuming one service always maps to exactly one container
- Assuming task/container mappings never change during a request
- Exposing full container inspect payloads in the API response
- Crashing on partial metadata
