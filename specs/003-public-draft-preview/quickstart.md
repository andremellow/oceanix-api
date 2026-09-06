# Validation Guide: Public Draft Preview

This is a future implementation validation guide, not evidence of implemented behavior.

## Prerequisites

Approve the plan/execution contract; validate the current Toscanini preflight and confirm its canonical verification command. Use a proven local disposable test database, fake video provider and mocked outbound APIs. Do not change .env or create production records. Use factories in tests; QA fixture creation needs a verified disposable environment. Do not run composer setup (it can alter environment configuration).

## Automated verification

After implementation, run the focused preview Pest files named in tasks.md with php artisan test --compact followed by impacted authorization, course lifecycle, video, and locale regressions. Cover the AC matrix in architecture.md with distinctly named company A/B, platform accounts, two versions, two compositions, distinct questions and multiple videos. Use time travel for exact boundaries and provider latency. Http::fake external calls and assert no unintended requests. Assert persistence tables remain unchanged for preview-only requests.

Run generation/generation and generation/publication races against isolated PostgreSQL using independent connections; prove one active generation and lifecycle-safe resolution. SQLite green tests alone are insufficient for that gate. Do not run concurrency tests against the developer or production database.

Required repository checks before delivery/PR: composer test; vendor/bin/pint (including --dirty --format agent for changed PHP); npm run build. Then contract validator, contract-aware Toscanini completion gate with architecture/design requirements, and toscanini verify --run-id public-draft-preview-20260905. Any unavailable or failed gate remains blocked. The current tooling reaches approval validation; pending review/product approvals must not be treated as passed.

## Executable QA matrix

| AC | Setup/action | Expected evidence |
|---|---|---|
| 01 | Company author and platform author open exact draft, generate and copy; denied actor attempts same | correct permission controls, reusable URL, denied direct requests and revocation |
| 02 | Open before/at expiry; renew expired link and retry both | exact server boundary, new URL works, old one denied |
| 03 | Two courses/versions/items; save edit, reload preview, tamper item | current saved exact-version content only; no cross-scope data |
| 04 | Open assessment and video; inspect state before/after | static choices, no answers/keys, zero training/evidence changes |
| 05 | Publish/archive/discard with preview open; navigate/renew video | friendly ended UI and denied new application grants |
| 06 | Fresh browser with English preference, then explicit English switch; 390px/1440px and keyboard | pt-BR default, explicit locale respected, usable controls and layout |
| 07 | Play/renew media; simulate provider error, expiry, replaced media | bounded temporary grants, readable retry/end states, no event writes |
| 08 | Signed-out and unrelated inactive-company/platform sessions | same valid public access and actual local signed media GET/Range without tenant/session contamination |

QA records setup, action, expected/observed, screenshots, recent console/network/application errors and cleanup. Inspect clipboard rejection, loading/empty states and token/referrer/cache leakage. Credentials in URLs must be redacted in retained evidence. Confirm existing training player and internal preview regressions remain green.

## Review and release

One Worker writes production/tests. Code Reviewer and Test Analyst review the same stable checkpoint before executable QA. Independent architecture/design alignment follows as applicable. Consolidate findings before remediation; reopen gates by impact. Preserve generation rows on rollback; remove public route exposure rather than dropping operational records.
