# Execution readiness

Prepare this assessment during planning and finish it after task generation, before the Worker starts. It is an orchestrator responsibility, not another specialist or review round. Inspect the full accepted implementation, build, test and QA path so licensed SDK access is discovered before a long unattended handoff.

Assess all six categories. Use multiple checks when a category has multiple prerequisites; one verified public package must not conceal a blocked private package.

| Category | Inspect and prove, when required |
| --- | --- |
| runtimes | Runtime/SDK versions, build tools, native toolchains and executable availability |
| dependencies | Manifests, lockfiles, installed dependencies, planned new packages and actual registry resolution |
| access | Private registries, licensed dependencies, accounts, credentials and necessary access scopes |
| services | Databases, queues, APIs, emulators/devices and reachable local or remote environments |
| verification | Runnable build/test/QA path, representative fixtures and test accounts; note baseline failures separately |
| permissions | Outstanding decisions and permissions for necessary setup, external mutations or paid services |

Run cheap real probes in the Worker's execution environment using existing authorization. Finish required setup before marking it verified. Do not broaden access, install optional recommendations, incur new charges, or demand production access for a local task. An unavailable optional capability is `not-required` only with a reason tied to the accepted path. Required pending work cannot be waived by relabeling it optional. If the owner changes the accepted scope, update and reapprove the contract and assessment.

Record `readiness` in schema v3 contracts. The following shows one check; the finished assessment includes every category and every required prerequisite:

```json
{
  "scopeId": "scope-1",
  "environment": "Worker local development environment",
  "checks": [
    {
      "category": "access",
      "name": "Licensed SDK private registry",
      "status": "pending",
      "evidence": "Registry resolution requires local authentication; owner setup is pending"
    }
  ]
}
```

The only ready statuses are `verified` with concrete nonsecret probe evidence and `not-required` with an applicability reason. Missing categories, pending/blocked checks, missing evidence, missing environment, or a changed frozen scope block execution. The contract preflight, project verification preflight, completion gate and Worker-start telemetry enforce the assessment. Architecture-only approval skips it. Historical schema v2 runs retain compatibility without fabricated readiness evidence.

While the user is available, present unresolved prerequisites together: the affected dependency, why it is needed, the exact configuration/decision required, and where it should be supplied securely. Store only availability results and safe references; never tokens, passwords, licence keys or raw authentication output. Reuse explicit setup authorization. After the user completes setup, rerun the affected probe before marking it verified.

Before an unattended handoff, summarize what was checked, whether any required user action remains, and known baseline limitations. If required actions remain, say execution is not yet ready rather than implying it will finish overnight. The runtime validates recorded evidence, not the truth of arbitrary prose or live future access. An outage or expired token can still interrupt execution. Refresh affected probes when environment, dependency selection or scope changes; avoid repetitive checks per agent. Continue independent authorized work when a newly discovered blocker does not affect it.
