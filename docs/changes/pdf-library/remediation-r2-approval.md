# Second bounded correction approval

On 2026-09-18 the owner requested “então arruma o que falta para eu poder testar” after being told the outstanding PDF opening failure and unapplied Herd migrations prevent testing. This authorizes the proposed second correction batch, specialist ceiling21 (15 starts already used), and local Herd setup limited to the two additive PDF-library migrations and asset build. No production deployment, PR update, unrelated changes or .env edits.

Correct only QA-R2-01: interrupted initial PDF opening must retain unsaved text, usable Save and retry. AC/INV, architecture hash and frozen QA matrix remain unchanged. Run one Worker, canonical verification, directed Code/Test checks, directed design only if affected, directed QA with retained prior full matrix, then final architecture conformance. No new full audit. Preserve existing concurrent compliance, composer and translation edits.

Readiness refresh: PHP8.4.14, Node20.20.2 and Herd CLI available; authenticated local migrate:status succeeded after sandbox approval. Only 2026_09_16_120000 and 2026_09_16_120001 are pending. No migrations executed yet. QA remains disposable; final Herd setup follows successful validation.
