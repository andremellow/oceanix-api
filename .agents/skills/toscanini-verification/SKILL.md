---
name: toscanini-verification
description: Validate an implementation against stable acceptance criteria using repository evidence, deterministic gates, tests, and an independent QA matrix.
---

# Toscanini Verification

Start from the accepted requirements, not the implementer's explanation. Run the canonical verification command if one exists. Validate happy paths, negative paths, permissions, tenant isolation, validation, failures, regression risks, responsive behavior, browser console errors, and network failures when applicable.

Map every acceptance criterion to implementation evidence, effective automated evidence, executable QA evidence when applicable, and a result. Automated verification, test-effectiveness audit, and runtime QA are separate gates.

The Test Expert is read-only and evaluates whether tests execute the changed behavior with representative state and assertions that fail for the right reason. QA does not review or edit code or tests; it exercises the real UI or API and may create temporary data only in a proven local, ephemeral, or explicitly designated test environment. QA reports `BLOCKED` if safe executable validation is unavailable. A failure returns to the Worker, and all affected gates run again in fresh contexts. Conclude only after the required gates are approved.
