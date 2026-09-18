# Local test readiness

2026-09-18, owner explicitly requested fixing outstanding work to test. Herd site oceanix-api maps this checkout, PHP8.4. Before writes, migrate:status identified only the two feature migrations pending and migrate --pretend confirmed additive schema/catalog effects.

After all required implementation gates passed, ran artisan migrate with exactly these two --path arguments:

- database/migrations/2026_09_16_120000_create_lesson_document_archives_table.php
- database/migrations/2026_09_16_120001_project_lesson_document_permission_catalog.php

Both DONE, batch12; subsequent status confirms Ran. No other migration, reset, seed or role-grant backfill. Existing operational records/PDF bytes retained. No .env edits. Current asset build passed; no public/hot file. https://oceanix-api.test responds302 to unauthenticated request, as expected. Actual feature UI behavior was validated with isolated synthetic QA, not by mutating Herd training content. User can now test authenticated editing on this local site. Non-admin users need the existing profile's explicit PDF capabilities.
