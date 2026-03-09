# Docker Swarm Discovery Checklist

- [ ] Docker access is isolated in `App\Infrastructure\Docker`
- [ ] Discovery does not rely on controllers or HTTP routes
- [ ] Services, tasks, and containers are mapped explicitly
- [ ] Partial / missing task-container mappings are handled safely
- [ ] Output is normalized into typed DTOs or stable contracts
- [ ] Only required metadata is collected
- [ ] Structured logs are emitted for discovery counts and failures
- [ ] Tests cover happy path and degraded path
- [ ] No destructive Docker action was introduced
- [ ] No raw user input is passed directly into Docker calls

