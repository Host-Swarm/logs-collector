# Done Criteria

A task is not complete unless it includes, where applicable:

- clear config entries for any new environment variables
- typed DTOs or contracts for request/response shapes
- tests for success and failure paths (auth success, auth failure, Docker error, bad input)
- structured logs with relevant context (container_id, endpoint, error)
- container ID validation before any Docker API call
- no secret or token values appearing in logs
- no direct `env()` usage outside config files
- no business or Docker logic in controllers
- no unauthenticated Docker operations reachable via HTTP
- authentication handled exclusively in middleware — not in controllers or services
- a short note in the relevant guideline if a new auth pattern, config key, or Docker operation was introduced
