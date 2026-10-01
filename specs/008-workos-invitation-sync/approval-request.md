# Product-owner approval request

Status: PENDING. Run 20261001-workos-invitation-sync. Scope workos-invitation-sync-v1. Critical assurance; no implementation started.

Approve these explicit artifacts and the frozen execution contract to authorize task generation and one Worker:

| Artifact | SHA-256 |
| --- | --- |
| spec.md | 74891c194a805e002ca542d1b606f47a8ff295cf885347159c3cdffec0277050 |
| scenarios.md | 8ac919c0747079289ce13421f6cc8832f78debac5bb87a5d0975d18151d2a2bb |
| plan.md | 172d0f63abfc001296d81668955d14593ffede98c82b90e20b2e98e0a7314c9e |
| architecture.md | f490c214453d7efa9672e5ba5408316adcf777e9deec1aed1d41f3ee4356ba51 |
| design.md | 4d00e843682b85d879a53208a7a3b4512fb8a3a796f16ca67ebe410bd7968479 |
| .toscanini/runtime/runs/20261001-workos-invitation-sync/execution-contract.json | 10a5d004fdb79273035376fe0c95225344ca19f49e365baa63a4a473ebd5594b |

Material transition: existing Active people lacking reliable local access history become Invited until next successful authorized tenant access. No historical access timestamp is fabricated. This may relabel prior users too because old access was not recorded. No suspension/termination/assignment/role/history is removed.

Any status can receive assignments. Only successful tenant access activates an invited person. Provider invitation states and WorkOS last-sign-in remain separate evidence. Synchronization never sends email. Default invitation recovery excludes revoked/accepted/current members/blocked statuses; revoked reissue needs explicit selection. Missing provider evidence is not proof that a person never logged in. Production synchronization, deployment and bulk emails are outside this development run.

No new requirement, scenario or regression surface can be added after approval without explicit amendment. Remaining preflight findings must be approval-only; new feature verification evidence is NOT_RUN.
