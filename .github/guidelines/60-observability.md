# Observability

This project is part of the observability chain, so its own observability must be strong.

Track at minimum:

- docker connectivity status
- upstream socket connectivity status
- active services being watched
- active containers being watched
- log events received per minute
- log events forwarded per minute
- failed forwards
- dropped events
- reconnect attempts
- queue depth / buffer depth if buffering is used

Expose health information through safe internal endpoints or commands.

Prefer structured logs and machine-readable health summaries.
