---
name: docker-swarm-discovery
description: Discover Docker Swarm services, tasks, and concrete running containers safely inside host-swarm-logs-collector, then map them into a stable hierarchy for downstream log streaming and forwarding.
---

# Purpose

Use this skill when the task involves any of the following inside `host-swarm-logs-collector`:

- discovering Docker Swarm services
- resolving tasks for a service
- mapping tasks to running containers
- collecting container metadata needed for log streaming
- building or repairing the hierarchy:
  
  swarm > service > container
- handling discovery drift caused by rolling deploys, restarts, scaling, or task replacement

This skill is for **read-oriented swarm discovery**. It should not introduce destructive Docker actions.

# Success criteria

A correct implementation:

1. reads swarm/service/container state from Docker through a dedicated infrastructure layer
2. produces a stable and normalized representation of:
   - swarm
   - service
   - task
   - container
3. avoids leaking Docker internals into controllers or request handlers
4. tolerates missing or stale task/container mappings
5. exposes enough metadata for log collection without over-collecting sensitive data
6. provides structured logs and tests for discovery success and failure paths

# Architecture expectations

Keep these responsibilities separated:

- `App\Infrastructure\Docker\...`
  
  - raw Docker API access
  - listing services
  - listing tasks
  - inspecting containers
  - translating API responses into infrastructure DTOs or arrays
- `App\Domain\Logs\Services\...`
  
  - swarm hierarchy orchestration
  - mapping, filtering, normalization
  - deciding which services/containers are eligible for log streaming
- `App\Domain\Logs\DTOs\...`
  
  - typed normalized records used by the rest of the app
- `App\Jobs\...`
  
  - deferred refresh / retry / reconciliation

Do not place Docker discovery logic in controllers, commands, or socket transport classes.

# Discovery workflow

Follow this order unless the repository already has a stronger established pattern:

1. Confirm Docker connectivity through the mounted socket.
2. Resolve swarm identity or configured swarm key.
3. List relevant Swarm services.
4. For each service, list service tasks.
5. Filter tasks to relevant states where appropriate.
6. Resolve the concrete container backing each task when available.
7. Inspect the container only when needed for log collection metadata.
8. Normalize discovery results into a stable hierarchy.
9. Emit structured internal logs about discovery counts and failures.
10. Cache or checkpoint only if the app already has a clear strategy for it.

# Data contract expectations

Discovery should produce a normalized shape that is stable even when Docker data is incomplete.

At minimum, a normalized discovered container record should support:

- swarm key or swarm id
- service id
- service name
- task id if available
- task slot if available
- container id
- container name
- container state/status if available
- node id / node hostname if available
- labels subset if explicitly allowed
- discovered timestamp

Do not assume every task has a currently inspectable container.
Do not assume every container name is present or canonical.
Do not crash on partial metadata.

# Filtering rules

Prefer explicit eligibility checks.

Examples of good filtering:

- only running services or tasks if the use case requires active log streams
- only containers that belong to swarm tasks
- only labels or metadata needed downstream

Avoid vague or hidden filtering rules.

If a service is excluded, make the reason observable through structured logs.

# Safety rules

Docker socket access is privileged.

Always follow these rules:

- prefer read-oriented Docker APIs
- never expose raw Docker actions to public routes
- never pass raw user input directly into Docker inspection calls
- validate identifiers before using them
- avoid shelling out when an API client or HTTP-over-socket client exists
- collect only the metadata needed for log streaming and correlation

# Failure handling

Discovery must assume infrastructure drift and partial failure are normal.

Handle at least these cases:

- Docker socket unavailable
- Docker API timeout
- service exists but tasks are empty
- task exists but container cannot be resolved
- container inspect fails
- rolling update replaces task/container during discovery
- duplicate or stale mappings appear temporarily

Return partial results when safe instead of failing the whole pipeline.
Surface degraded states through structured logs.

# Required deliverable for implementation tasks

When using this skill, the final implementation should include:

1. discovery code in the correct layer
2. normalized DTOs or contracts
3. structured logs for counts and failures
4. tests for:
   - happy path
   - missing task/container mapping
   - Docker connectivity failure
   - partial discovery result
5. a concise summary of:
   - files changed
   - mapping assumptions
   - risks or follow-ups

# Anti-patterns

Avoid the following:

- mixing Docker discovery with websocket send logic
- returning ad hoc nested arrays from many places
- using controllers for discovery orchestration
- assuming one service equals one container
- assuming task/container mappings never change
- exposing full container inspect payloads downstream
- silently swallowing discovery failures

# Output format for analysis/debug tasks

When this skill is used for debugging or design, organize findings under:

- current discovery path
- mapping assumptions
- failure points
- recommended fix
- tests to add

