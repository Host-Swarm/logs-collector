---
name: debug-log-forwarding
description: Diagnose why host-swarm-logs-collector is not forwarding Docker Swarm container logs correctly to the upstream server-manager socket connection.
---
# Purpose

Use this skill when logs are missing, delayed, duplicated, malformed, or failing to reach the main server-manager app.

# Debug workflow

Work through the pipeline in order:

1. Docker access

   - confirm docker socket mount assumptions
   - confirm container/service discovery is working
   - confirm logs are actually being read
2. Normalization

   - inspect raw lines
   - inspect timestamp/channel parsing
   - verify mapping to swarm > service > container > logs
3. Outbound socket

   - verify endpoint config
   - verify auth / token / handshake assumptions
   - verify reconnect state
   - verify send and ack behavior if applicable
4. Buffering / queues

   - inspect dropped events
   - inspect queue lag
   - inspect replay or backoff behavior
5. Upstream compatibility

   - compare emitted payload with expected server-manager contract

# Required deliverable

Return findings under:

- probable root cause
- evidence
- minimal fix
- hardening recommendations
- tests to add

# Important rules

Do not jump to websocket blame first.
Trace the pipeline from source to destination.
Prefer evidence from logs, config, tests, and code paths.
