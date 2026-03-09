# Done Criteria

A task is not complete unless it includes, where applicable:

- clear config entries
- typed DTOs or contracts for payloads
- tests for success and failure paths
- structured logs
- retry / reconnect behavior
- no secret leakage in logs
- no direct `env()` usage outside config
- no business logic in controllers
- no unsafe raw docker operations exposed to request handlers
- short documentation note if a new stream contract or config key was introduced
