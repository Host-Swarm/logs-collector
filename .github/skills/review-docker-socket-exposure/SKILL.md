---
name: review-docker-socket-exposure
description: Review host-swarm-logs-collector for security and operational risks related to mounting and using Docker socket access.
---
# Purpose

Use this skill when reviewing the safety of the collector architecture, especially around `docker.sock`.

# Review workflow

1. Identify all code paths that touch Docker.
2. Classify each as:
   - discovery
   - read-only metadata access
   - log streaming
   - write or destructive action
3. Flag any path that:
   - accepts raw user input into Docker calls
   - shells out unnecessarily
   - exposes Docker actions via HTTP or public endpoints
   - leaks secrets or sensitive metadata in logs
   - has no timeouts, limits, or validation
4. Review container/runtime assumptions:
   - mount scope
   - privileged mode assumptions
   - network exposure
   - secrets / tokens / upstream credentials
5. Recommend the least-invasive hardening improvements.

# Output format

Return:

- risk summary
- findings by severity
- exact files / areas to review
- recommended mitigations
- quick wins vs later improvements

# Rules

Prefer practical mitigations.
Avoid generic security advice not grounded in this repository.
