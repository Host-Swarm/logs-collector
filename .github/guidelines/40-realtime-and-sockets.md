# Realtime and Socket Rules

This app sends logs to the main `server-manager` over a socket connection.

## Requirements

- Connection lifecycle must be explicit.
- Reconnects must use backoff.
- Failed sends must be observable.
- Event payloads must be normalized before sending.
- Avoid blocking the log reader because of one failing upstream send.

## Payload design

Use a stable payload contract.
A normalized payload should usually contain:

- event type
- timestamp
- swarm identifier
- service identifier and name
- container identifier and name
- log channel if known (`stdout` / `stderr`)
- raw line
- parsed or normalized message
- metadata

Do not send ad hoc payload shapes from different parts of the app.

## Delivery

Prefer durable delivery semantics where feasible:

- acknowledge what the upstream accepted if protocol supports it
- otherwise track send failures and reconnect state clearly

## Backpressure

Assume logs can spike.
Design buffering and batching carefully.
Do not let unbounded memory growth occur.
