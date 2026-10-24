# Validation guide

Use isolated worktrees and synthetic SQLite/browser data. Never copy .env or run RefreshDatabase against Herd. Reuse installed locked dependencies without changing package versions. Fake WorkOS calls; integration QA connects isolated Account and Compliance processes only.

1. Before code changes, run focused existing PlatformAdministration, TenantIsolation and ProvisionCompany tests and retain baseline results.
2. Add targeted denial/UI tests for SC-01–04 and show failure for the intended old behavior.
3. Implement only the frozen paths, then rerun targeted checks and Account existing creation/import/enable coverage for SC-05–08.
4. Run repository canonical verification as required; unrelated baseline failures are recorded, not fixed.
5. Independently exercise the frozen QA matrix in execution-contract.json. Do not browse/review unrelated screens except setup navigation.

Exact fixture/server commands and evidence will be recorded in readiness before Worker dispatch. This guide is a plan, not verification evidence.
