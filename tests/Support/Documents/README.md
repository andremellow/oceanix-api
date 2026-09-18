# Disposable PDF QA

## PDF library extension (2026-09-16)

The fixture includes 65 active PDFs per company/platform owner, a second company, duplicate/long names, and a separately grantable `libraryUser`. `manifest.json` provides actual identities and owner-specific UUID lists. The existing `author` is an administrator for the original company and platform; `authorB` belongs only to the second company. No production fixtures or `.env` changes are involved.

`revoke-library` and `restore-library` detach/restore only the disposable library profile, retaining course editing access. `fail-list`, `fail-reuse`, `fail-archive` and corresponding `restore-*` commands toggle test-only query faults in `QaBootstrap.php`. These are never loaded by production. The browser suite exercises actual modal updates, scoped private routes, selection/caret after settled updates, confirmation cancellation, revocation, failures and held late completion. Screenshots and fixtures are retained in its reported directory.

Run `PostgresRace.php` with a fresh `OCEANIX_PDF_QA_DIR=/tmp/oceanix-pdf-qa-XXXXXX`. It accepts only loopback PostgreSQL and exact run-owned database `oceanix_pdf_library_qa_20260916_085729b9`, verifies `current_database()` before additive migrations, and never resets a schema. Provisioning evidence: `docs/changes/pdf-library/postgres-readiness.md`. Two child processes execute actual Archive/Reuse Actions, a query listener holds the first acquired document lock, and the parent observes the opposing backend waiting in `pg_stat_activity` before releasing the barrier. Both commit orders, idempotency, bytes/metadata and actual pending `SaveCompanyCourseEditorDraft` are asserted with bounded waits/timeouts. Requires local PostgreSQL access; SQLite is not concurrency evidence. Records remain for independent validation.

This standalone harness is loaded only when explicitly selected as the PHP development-server router. It has no production route/provider registration. It rejects any directory outside `/tmp/oceanix-pdf-qa-*`, boots `testing`, overrides SQLite/session/upload/private-file locations into that directory and prevents external HTTP calls. It never changes `.env` or development database rows.

Create a unique directory with `mktemp -d /tmp/oceanix-pdf-qa-XXXXXX`. Set `OCEANIX_PDF_QA_DIR` to the returned absolute path when running:

```sh
php tests/Support/Documents/QaFixture.php
php -S 127.0.0.1:8768 -t public tests/Support/Documents/QaRouter.php
```

The fixture writes `manifest.json` in that directory. Visit `/__pdf-qa/login/{id}` using its author, learner or unrelated ID, then visit the named editor/preview/learner path. Author is a synthetic company/platform administrator; learner has one obligation; unrelated has none. Use a separate browser context to prove anonymous denial. The manifest's `preview` URL is an existing token preview. The harness leaves data in place for inspection; stop the server when finished.

Upload `tests/Fixtures/lesson-guide.pdf` through the real PDF toolbar. All three editors contain selectable `Read guide` text surrounded by bold text and an ordinary link. Shared course and standalone editor intentionally share one module version. The company lesson also contains a pre-saved PDF for learner/new-tab checks. Read routes require saved anchor membership; unsaved uploads cannot be read.

For frozen failure/revocation scenarios, invoke the same fixture script with `revoke <assignment-id>`, `expire-preview`, `fail-storage` or `restore-storage`, retaining the same directory environment variable. These commands mutate only disposable fixture state. Failed storage makes the dedicated PDF disk point at the fixture database *as a directory*, causing the filesystem adapter to reject writing before metadata creation; restore it for retry. No database bytes are overwritten.

Automated browser evidence: `node --test tests/JavaScript/lesson-documents.browser.test.mjs` creates a separate directory/server/browser and exercises actual upload, selection, save/reload and popup. Pest's browser bridge cannot transfer multipart file bodies on this installed runtime, so that suite uses direct Playwright with the same real Laravel kernel. Focused Pest action/Livewire/access checks remain under `tests/Feature/Documents`.

QA-08: First upload and Save a PDF in the shared-module editor, then execute `php tests/Support/Documents/QaFixture.php prepare-copy` with the same directory environment. This fixture preparation marks the source published and calls the real `CreateModuleDraft` Action. Inspect its `copy.json` (source/draft HTML and retained UUIDs), reload the shared-module editor/preview and open the copied link. While logged in as the fixture author, request `/__pdf-qa/published-upload`: this harness-only endpoint calls the real upload Action with the published source record and returns denial (404); verify the published source PDF through the original shared-course preview still works. All records belong only to this disposable database.

QA-05 unrelated-document control: take the company PDF UUID from manifest `document`, substitute it for the UUID in the shared-module preview's document link, and request that URL. The shared module has no association with the company document, so it returns 404. Use the original shared-module PDF link as the positive control. `expire-preview` then makes the manifest's token preview and its document URL return 410.
