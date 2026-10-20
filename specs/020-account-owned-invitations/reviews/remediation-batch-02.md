# Consolidated remediation batch 02

Final permitted correction round; scope, AC/INV and QA01–23 unchanged. Both round1 directed reviewers have terminated. Fix CR-06, CR-07 and Compliance TA-01 using existing approved paths. Required outcomes and exact causal evidence are in code-review-directed-01.md and test-analysis-directed-01.md. All original CR outcomes require an isolated directed certification after the coordination contamination; prior reports/verdicts remain unchanged. TA02–08 directed closures retained unless the correction affects them.

Nonblocking deployment clarification under existing T029: in both approved docs/production-integration.md, remove obsolete UUID allowlist instructions and obsolete import-to-Compliance statements from earlier sections so they agree with the approved protocol2 section. No new product behavior/configuration or QA scenario.

No Account tooling/config/services.php/.env additions. Preserve unrelated dirty files. One Worker; scoped red/causal tests, formatting, deterministic checks, new immutable checkpoint, directed review only. No source publication or gate waiver.
