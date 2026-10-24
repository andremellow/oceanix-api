# Owner approval request — version1

Scope: `account-owned-invitations-v1`. Run: `20261020-account-owned-invitations`.

Approve/reject the concrete proposal in approval-summary.md and spec.md, scenario map, plan, design constraints, architecture and exact per-repository path inventory. The user has authorized preparation only; approval remains PENDING. No Worker tasks/source changes follow until approval AND execution readiness.

Architecture author: APPROVED_WITH_CONDITIONS; conditions are explicit owner approval and operational preparation, not hidden competing design decisions. Author evidence: reviews/architecture-author.md.

## Bound artifacts

| Artifact | SHA-256 |
| --- | --- |
| spec.md | `2f37c2a191bf34bdb2ec6e7d0fdec9fb1226deef1ad5bb29dce2afa0bdd7d21b` |
| scenarios.md | `f3074553396f1422300c522ecf0ad956adab1f579dc7650c23ac0784513888d0` |
| plan.md | `583bfbad1ce1276cced43c6912d30c449d159851444d071fae7c04f627f2ddd1` |
| architecture.md | `490eb8c361fc7cbdb60f3ae8d0b33843fb25c1c140a243df5e2a9f8294310ecb` |
| architecture-paths.json | `66ce6a6c288907ca00116863016e777d13ac283bcab87b956ed0709e918d87b6` |
| design-constraints.md | `6a74f43ff5f8961905ff77162fbc73069e2f9bc7e0dbb461d0bac30e7c4264b6` |
| contracts/http-invitations.md | `49f44648d3a7a1184c0a96b20f6c88f4a9df2caeb9c2c87c15871f0d645c81c5` |
| Compliance execution contract | `6efefc828e5298fa2b01ca9a9d8a7fcad6b02798b86f8e979db7bfa58c6a2244` |
| Account execution contract | `f6749e6e82f1417989637944edf11c556b3a8b52bb3d7de32ccacb216ce72cdb` |

## Approval meaning

Acceptance confirms D01–05 and P01–04 as rendered, all23 SC/QA examples, independent product/user authority checks, the exact136 candidate change paths (72Account/64Compliance, including tests/docs) and critical assurance. The path list is a permitted mutation boundary, not a promise every file must change; reviewers validate only changed hunks/feature behavior. Any new requirement/path/QA criterion returns for amendment.

Missing private service/queue/PostgreSQL readiness remains an execution blocker until actual probes pass. No production keys requested, no production commands/rollout authorized. Existing unrelated dirty files remain outside the feature. Approval does not waive these conditions or approve unrun tests.

To approve, explicitly refer to version1 / scope above and these documents. A requested revision changes the relevant hashes and requires a new version; reading the report is not approval. This exact architecture SHA is immutable after approval. Task generation occurs next, then one Worker after readiness, full review checkpoint, executable QA and Architect conformance.
