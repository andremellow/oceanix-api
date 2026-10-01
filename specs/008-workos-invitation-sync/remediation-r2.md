# Worker QA fixture correction — round 2

Run 20261001-workos-invitation-sync; scope workos-invitation-sync-v1. Directed correction only for QA-ENV-001. This is Worker evidence; independent QA closure remains pending.

Moved the existing PHP imports in tests/Support/InvitationQa/postgres-probe.php above the executable bootstrap. Kernel::class now resolves to Illuminate\Contracts\Console\Kernel when bootstrap runs. No import names, disposable database/socket guard, schema setup, real two-process QueueWorkosInvitations seam, lock timing or result assertions changed. No app/UI/automated suite/tooling/approved artifact changes.

Original independent QA execution exited 255 with Target class [Kernel] does not exist. The exact command prescribed in qa-operator.md now exits 0 and reports:

```json
{"database":"disposable PostgreSQL","row_lock_blocks_competing_workers":true,"concurrent_results":["0","1"],"open_attempts":1}
```

Raw execution evidence: /private/tmp/invite-qa-env-remediation-probe.log. PHP lint and scoped Pint both exit 0. Only the already-authorized disposable oceanix_invitation_probe database on the isolated UNIX socket was reset. No UI interaction, environment-file change, real provider/email, existing database or deployment occurred. Full independent QA remains incomplete; this correction grants no delivery approval.
