# Proposed test-only scope amendment 01

Scope: invitation-validation-repair-v1. Add only Compliance `tests/Feature/People/WorkosInvitationSyncTest.php` to the existing mutation inventory. No production path, configuration, environment file, acceptance criterion, invariant or QA scenario is added.

The full canonical run found five existing synchronization tests whose linked-person fixtures have no successful invitation verification and no exact Account company binding. Under the approved repair, that untouched baseline now correctly requires mapping before creating a new reconcile request. Those tests instead exercise already-established synchronization, polling, recovery and concurrency, and simulate only the reconcile receipt. Their incomplete fixture triggers mapping rather than the boundary under test.

Declare those established fixture baselines explicitly with a historical `invitation_verified_at`, preserving each test's own timestamp overrides, original request-count/status/ordering/access assertions, denial controls and cases. Do not disable baseline initialization, fabricate successful delivery, remove assertions, broaden HTTP fakes or change production behavior to avoid the guard. New repair tests continue proving the actual unknown-baseline mapping path.

A separate initial implementation regression in the already-approved AdoptPeopleIntoAccount path is being corrected: baseline discovery is needed for new reconciliation, not for explicit send on an already-linked person. It requires no scope expansion.

Owner decision: pending. The AGENTS.md closed-scope rule requires approval before editing the additional test file. All other approved work can continue.
