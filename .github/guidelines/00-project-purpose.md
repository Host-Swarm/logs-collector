---
# `.ai/guidelines/00-project-purpose.md`

```md
# Project Purpose

This repository implements **host-swarm-logs-collector**, a Laravel service responsible for collecting Docker Swarm container logs and forwarding them to the upstream server-manager.

The application mounts the Docker socket and continuously streams logs from running containers.

Logs are normalized and sent upstream via websocket.
---

# Core Responsibilities

The collector performs the following tasks:

1. Discover Docker Swarm services and containers
2. Subscribe to container log streams
3. Normalize log entries into structured events
4. Forward events to server-manager
5. Retry delivery during network interruptions
6. Provide observability into the log pipeline

This application is part of the Host Swarm observability layer.

It does **not** manage deployments or infrastructure.

---

# Golden Rules

These rules must always be respected.

1. This application **observes and forwards logs only**.
   It must never orchestrate swarm deployments.
2. Docker socket access is **highly privileged**.
   Only minimal Docker operations are allowed.
3. All emitted log payloads must follow **one normalized event structure**.
4. Upstream websocket failures must **never silently drop logs**.
5. Burst traffic and reconnect events must be handled gracefully.
6. Default test suites must **not require a live Docker Swarm cluster**.
7. Controllers must never contain Docker logic.
8. Secrets and tokens must **never appear in application logs**.

---

# Non Responsibilities

The collector must not:

• control swarm deployments
• execute docker write operations
• expose docker APIs to external clients
• store full long-term log history

The collector is a **log transport component only**.

