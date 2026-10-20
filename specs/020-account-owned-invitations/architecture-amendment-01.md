# Architecture amendment 01: Account cutover configuration path

Date: 2026-10-20. Mode: directed architecture authoring; no new whole-scope review.
Scope: `account-owned-invitations-v1`.
Original architecture: `specs/020-account-owned-invitations/architecture.md`.
Original architecture SHA-256: `490eb8c361fc7cbdb60f3ae8d0b33843fb25c1c140a243df5e2a9f8294310ecb`.

## Proposed amendment

Add exactly one existing file to Account's approved `changeBoundary` and `codeReviewPaths`:

- Repository: `/Users/andrepiresdemello/Code/Oceanix/Account`
- Existing path: `config/services.php`

The original architecture §7.6 assigns configuration ownership and §7.7 already requires a default-off operational cutover flag. The Account inventory omitted its conventional configuration file, preventing normal environment configuration without editing an unapproved path. This amendment corrects that path omission and makes the previously specified flag concrete; it adds no behavior or validation scenario.

Add this entry within the existing returned configuration array, preserving unrelated entries:

```php
'account_owned_invitations' => [
    'enabled' => env('ACCOUNT_OWNED_INVITATIONS_ENABLED', false),
],
```

Application code reads `config('services.account_owned_invitations.enabled', false)`; it must not call `env()` outside the configuration file. An absent environment value leaves centralized operational dispatch disabled. This follows the existing Account `config/services.php` convention, supports Laravel configuration caching, and introduces no package or new configuration layer.

No `.env` edit, environment-value change, deployment or enabling of the flag is authorized by this amendment. Secure operator setup and the original pair-readiness conditions remain required before enabling operations. Local-only import remains independent of this flag and of Compliance connectivity, as already specified.

## Unchanged contract and conditions

All original architecture decisions, FR/AC/INV identifiers, QA scenario IDs, regression surfaces, assurance and operational prerequisites remain unchanged. Existing SC-22 / QA-22 covers default-off cutover and safe rollout; no new scenario is proposed. Compliance already includes `config/services.php` in its frozen inventory and requires no added path. Original architecture, inventory and execution contracts remain unmodified by the architect.

Only this added Account file's cutover configuration entry is authorized for the proposed scope amendment. Unrelated configuration changes remain outside scope. After explicit owner approval, the orchestrator records this amendment hash alongside the original architecture hash and adds the exact path to Account's contract/inventory; existing approval of the original document must not be presented as approval of this amendment.

## Author verdict and owner decision

Author readiness: **APPROVED** for this narrow amendment. No unresolved architecture choice or new operational condition is introduced. Original execution-readiness requirements continue to apply.

Product-owner approval: **PENDING**. This document is a concrete proposal for approval, not permission to edit Account configuration. Approval must bind this amendment's SHA-256 and scope `account-owned-invitations-v1`. Independent work on already-approved paths may continue while this added-path decision is pending.
