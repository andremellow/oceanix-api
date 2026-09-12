---
name: toscanini-verification
description: Validate an implementation against stable acceptance criteria using repository evidence, deterministic gates, tests, and an independent QA matrix.
---

# Toscanini Verification

Start from the accepted requirements, not the implementer's explanation. Run the canonical verification command if one exists. Validate happy paths, negative paths, permissions, tenant isolation, validation, failures, regression risks, responsive behavior, browser console errors, and network failures when applicable.

Map every acceptance criterion and architecture invariant to implementation evidence, effective automated evidence, executable QA evidence when applicable, and a result. Automated verification, test-effectiveness analysis, and runtime QA are separate gates.

The Test Analyst is read-only and evaluates whether tests execute the changed behavior with representative state and assertions that fail for the right reason. QA does not review or edit code or tests; it exercises the real UI or API and may create temporary data only in a proven local, ephemeral, or explicitly designated test environment. QA reports `BLOCKED` if safe executable validation is unavailable.

Code Review and Test Analyst run against the same stable checkpoint before QA. Each returns its complete finding set. After one consolidated correction batch, deterministic verification runs once and only affected gates reopen for directed remediation checks. After QA, the Architect checks implementation conformance once when architecture exists; later architecture-affecting corrections receive directed revalidation. Conclude only after all in-contract blocking findings are resolved and required gates approve.
