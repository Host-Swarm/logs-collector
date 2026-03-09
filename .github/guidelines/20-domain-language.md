# Domain Language

Use consistent terminology.

swarm
Docker Swarm cluster

service
Docker Swarm service

container
Running container instance

log entry
A single log event

stream
Continuous log flow

collector
This Laravel application

server-manager
Upstream system receiving logs

Do not mix these terms.

- do not call a service a container
- do not call log entries messages unless the payload is explicitly socket-message oriented
- do not call the upstream app "client"; call it `server manager` or `upstream`

