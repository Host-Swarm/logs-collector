# Domain Language

Use consistent terminology across all code, logs, and documentation.

## Core Terms

**swarm**
Docker Swarm cluster.

**stack**
A logical group of Docker Swarm services deployed together (identified by the `com.docker.stack.namespace` label).

**service**
A Docker Swarm service within a stack.

**task**
A single scheduled unit of a service (a running or pending slot).

**container**
The concrete running container backing a task.

**log stream**
A continuous on-demand HTTP response streaming log lines from a container.

**exec session**
An interactive WebSocket session connected to a container's shell via Docker exec API.

**agent**
This Laravel application (host-swarm-agent).

**server-manager**
The upstream parent Laravel application (with Passport installed) that the agent serves.

**server secret**
The `SERVER_SECRET` environment value used to authenticate stack/service API requests.

**one-time token**
A short-lived Passport token issued by server-manager, scoped to a specific container, used to authorize a single log stream or exec session.

## Do Not Mix Terms

- Do not call a service a container.
- Do not call a task a container — they are related but distinct.
- Do not call the server-manager a "client"; call it `server-manager` or `upstream`.
- Do not call the one-time token a "session" — it is not reusable.
- Do not call log streaming "broadcasting" — there is no upstream push; logs are pulled on demand.
