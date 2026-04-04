---
# `.ai/guidelines/00-project-purpose.md`

```md
# Project Purpose

This repository implements **host-swarm-agent**, a Laravel HTTP service that exposes Docker Swarm state and container operations to the upstream **server-manager** application.

The service mounts the Docker socket and serves authenticated HTTP endpoints for inspecting swarm topology, streaming container logs, and opening interactive exec sessions into containers.

---

# Core Responsibilities

The agent performs the following tasks:

1. Expose a list of Docker Swarm stacks via HTTP API (authenticated with server secret)
2. Expose stack detail — services and their running containers/tasks — via HTTP API
3. Stream container logs on demand over HTTP (authenticated with one-time Passport token)
4. Provide interactive exec sessions into containers over HTTP (authenticated with one-time Passport token)
5. Validate one-time Passport tokens against the parent server-manager application
6. Provide observability into Docker connectivity and active sessions

This application is part of the Host Swarm management layer.

It does **not** manage deployments, orchestrate swarm changes, or store any persistent data.

---

# Golden Rules

These rules must always be respected.

1. This application **exposes Docker state and limited container operations only**.
   It must never orchestrate swarm deployments, scale services, or remove containers.
2. Docker socket access is **highly privileged**.
   Only minimal, validated Docker operations are allowed.
3. All API responses must follow **consistent, typed response shapes**.
4. Stack/service API requests must be authenticated with the server secret.
5. Log/exec requests must be authenticated with a **one-time Passport token** from the parent app.
6. Tokens must never be stored — validate on-the-fly and forget immediately.
7. Each access token is single-use — never reuse the same token for a second request.
8. Controllers must never contain Docker logic.
9. Secrets and tokens must **never appear in application logs**.
10. Container identifiers from request inputs must be **validated before use in Docker API calls**.

---

# Non Responsibilities

The agent must not:

- control swarm deployments or scaling
- execute docker write operations (create, remove, stop containers)
- store logs, sessions, or tokens in any persistent store
- reuse auth tokens across multiple requests
- expose unauthenticated Docker operations

The agent is a **secure read-and-exec bridge** between server-manager and Docker.
