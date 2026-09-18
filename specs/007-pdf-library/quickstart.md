# Validation quickstart — planned, not executed feature evidence

## Preconditions

- Approved specification, architecture and execution contract for pdf-library-20260916.
- Installed PHP/Laravel/Livewire/Flux dependencies, Node/browser dependencies and private filesystem support.
- Do not modify .env or run fixtures/migrations against the Herd database. Existing tests use isolated SQLite; executable UI QA uses tests/Support/Documents/README.md's disposable harness.

## Automated checks after implementation

Readiness provisioned an empty local PostgreSQL database `oceanix_pdf_library_qa_20260916_085729b9` on 2026-09-16. Two independent backend connections verified their database identity. Use isolated process configuration to target exactly this database for B-12's archive-first/reuse-first concurrency probe; never change .env. Assert current_database before fixtures or migrations. Connection credentials remain in existing local configuration and must not be copied into artifacts. This is readiness evidence only, not execution of B-12. Retain the database until validation finishes.

Run serially where shared test filesystem fixtures are involved:

```sh
php artisan test --compact tests/Feature/Documents
node --test tests/JavaScript/lesson-documents.browser.test.mjs
npm run test:editor:unit
npm run build
./vendor/bin/pint --test
toscanini verify --run-id pdf-library-20260916
```

These commands are planned; baseline passes before implementation are readiness only. New tests must map actual file/name/results and defect sensitivity into scenarios.md. Any PostgreSQL concurrency probe must target a verified disposable database; never exercise the race on development user data.

## Executable QA

Extend the existing disposable PDF harness with two company owners, a platform owner, >60 distinct files (including duplicate/long names), selected grants/denials, archived item, published and copied retained uses. Run every QA-01–08 from scenarios.md through the real modal/routes with actual uploads, requests and bytes. Record expected vs observed, screenshots where useful, console/network errors and cleanup. No authored test invocation substitutes for independent QA.

Expected: only own PDFs listed; private Open without editor mutation; reused link persists; archive removes future reuse but existing links retain identical contents; errors/cancel/revocation preserve content and deny unwanted effects. Reuse must leave plain continuation at the exact captured position after updates settle.

After QA and architecture conformance, run the contract-aware completion gate. Existing gate tooling issues are not a permission to bypass it; report any blocker as such and request a separate amendment if needed. A runtime failure does not broaden the frozen QA scope.
