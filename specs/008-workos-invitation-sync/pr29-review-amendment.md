# PR 29 — directed correction proposal

Scope: resolve review comments 4161200584 and 4161200585 only, preserving the approved invitation/access rules.

1. ReconcileWorkosInvitations: collect exact-email validated accepted identities from current/stored invitation and complete paginated invitation history; check organization membership for those identities as well as locally linked identity. Any validated active member suppresses recovery. Preserve fail-closed provider failure behavior and local access/status evidence.
2. CreateCompany: initialize a supplied company owner as Invited, preserving admin role and account linkage; successful tenant access remains the activation boundary.

Tests: fake WorkOS accepted-history identity with a newer expired invitation and no local WorkOS ID; active membership prevents create/resend; inactive membership remains recoverable; identity mismatch/provider failure prevents sending. Company owner starts Invited with null local-access timestamps and admin role, then activates through existing tenant-entry behavior.

Validation: affected Pest suites, Pint and production build; directed read-only review and test verification at the same final checkpoint. Reuse approved architecture and frozen regression surfaces; no UI changes, production synchronization or email, unrelated baseline fixes, gate-tool changes, or merge.

Budget amendment requested: one additional consolidated correction round and two directed specialist starts (critical ceiling 19). Existing incomplete QA, architecture-conformance and completion gate remain pending; this amendment does not waive them or claim full delivery.

Approval: product owner replied “sim” in this task on 2026-10-01.

Execution record: the existing validator enforces a hard maximum of two correction rounds and seventeen specialist starts per run. The approved additional batch is recorded as linked narrow run `20261001-pr29-review-corrections`, retaining the parent specification and architecture decisions. Its contract preflight passes. No validator changes or parent completion approvals are implied.

## Correction evidence

Implementation checkpoint: `faa85344ec75971b802a09308a612bf2cafdb429`. Both PR29-01 (active membership from accepted identities) and PR29-02 (supplied owner initially Invited) received directed Code Review and Test Analyst approval with no new material findings. The Worker demonstrated the original defects before correction. The three affected Pest suites passed: 57 cases, 331 assertions; directed Test Analyst independently executed the eight changed cases, 73 assertions. Known missing-dotenv warnings persist. Pint and production asset build passed.

`toscanini verify --run-id 20261001-pr29-review-corrections` passed contract preflight and executed canonical `composer verify`. It exited 1 on the sole previously reproduced, owner-deferred SharedCourseDraftIntegrityTest failure (1 failure, 1042 warnings, 5872 assertions). Remaining canonical stages do not run after that failure; production build and Pint were executed separately. The completion gate also remains nonzero, reporting missing gates, including QA/specification and recognition of directed approvals. Existing parent-run full QA/conformance and completion-tool limitations are retained. No full delivery approval or merge is claimed.

Correction-run specialist starts: 3 (one Worker, two directed checks); one consolidated batch. No new learning policy was applied. External PR review detected both implementation omissions; failureStage is implementation, with direct automated regressions added.
