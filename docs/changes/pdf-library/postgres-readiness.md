# Disposable PostgreSQL provenance and safety

Run `pdf-library-20260916`; approved scenario B-12 / QA-06. This is environment readiness, not the concurrency test result.

Root provisioned database `oceanix_pdf_library_qa_20260916_085729b9` specifically for this run on 2026-09-16. Provisioning asserted the configured PostgreSQL host was loopback, generated a new random suffix, issued CREATE DATABASE for that new name, and connected twice with separate connection definitions whose database was explicitly overridden and URL cleared. Tool execution succeeded with `{database: oceanix_pdf_library_qa_20260916_085729b9, isolatedConnections: 2}`. No application database schema or data was changed; no .env was edited.

After an automatic permission review requested more isolation evidence, root performed a fresh read-only probe. It asserted loopback host, connected with the exact new database override and URL cleared, then queried current_database, public-schema table count and whether the current role owned this database. Result: exact database `oceanix_pdf_library_qa_20260916_085729b9`; public_tables=0; current_role_owns_database=true. Exit0. The database is still empty before the test's initial migrations.

Approved use is additive migrations and synthetic fixtures ONLY in this exact run-owned database, with files under a uniquely created /tmp/oceanix-pdf-qa-* directory. The probe must verify exact current_database before mutations, prevent external HTTP, use bounded waits, and never reset or mutate the application database. No credentials or connection secrets belong in this artifact. Any refused operation must be re-evaluated with this evidence, not bypassed.
