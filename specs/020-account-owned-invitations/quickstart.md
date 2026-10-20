# Rollout and validation proposal

These are future implementation/runbook requirements, not commands to execute now. No feature migrations/endpoints/jobs below exist solely because this document describes them.

1. Approve spec/scenarios/plan, architecture hash and both execution contracts. Complete private pairedservice/worker/PostgreSQL readiness probes before Worker dispatch.
2. Implement and validate local-only Account import independently. It must work with old/offline Compliance and missing productconnection. Preview is read-only; apply touches Account only and stores declared association/provenance. Preserve prior remote operation history separately.
3. Inventory legacy pending/unknown/dispatched import and invitation work with read-only diagnostics. Stop old workers for cutover. Quarantine incompatible jobs without sending, deleting evidence or replaying uncertain operations.
4. Deploy both compatible implementations; configure product/system tokens and shared assertionsecret securely with no company UUIDlist. Verify actual module registration rather than trusting the current bootstrap command's generic success message. No secret values in chat/logs.
5. Verify actual companybindings and exact provideridentity. First-use mapping is automatic protocol work; conflicts are actionable, never permission to create duplicates.
6. Keep new operationaldispatch disabled until pair readiness, worker/scheduler deadlines and currentactorproof checks pass. No direct-provider fallback on old/missingreceiver.
7. Exercise SC01–23 in safe privatefixtures: providerfakes, actual API receipts and queue execution, PostgreSQL concurrency and changedUI browser controls. Existing AuthKit login positivecontrol stays direct.
8. Enable centralized operationalflows only after review/QA/conformance/canonicalgates. Operator verifies status from both origins and recovery per person. Production deployment remains separately authorized.

Source: architecture§7.7/8/9. All new tests/QA NOT_RUN. No production company/token inventory assumed. The initial one-time command may be safely repeated by design without remote effects or duplicate local records.
