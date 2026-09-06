# Tasks: Public Draft Preview

Input: approved spec.md, plan.md, architecture.md, data-model.md, ux.md, contracts/public-preview.md. Tests required by repository rules and accepted validation contract. One Worker owns all production/test edits.

## Phase 1: Setup

- [x] T001 Record accepted media boundary and execution authorization in specs/003-public-draft-preview/spec.md and .toscanini/runtime/runs/public-draft-preview-20260905/execution-contract.json.
- [x] T002 Complete independent architecture and specification review of specs/003-public-draft-preview and validate the execution contract before application edits.

## Phase 2: Foundation

- [x] T003 Add immutable generation model, additive migration and factory in app/Models/CoursePreviewLink.php, database/migrations/*create_course_preview_links_table.php and database/factories/CoursePreviewLinkFactory.php.
- [x] T004 Add tenant/platform atomic permissions and explicit ownership authority in app/Enums/Permission.php, app/Enums/PlatformPermission.php, app/Policies/CoursePolicy.php and app/Services/Courses/CoursePreviewAuthority.php.

## Phase 3: US1 — Share unpublished content (P1)

Goal: authorized company/platform authors generate a copyable link that anonymous recipients can open. Independent test: generate from both authoring contexts, open signed out, deny missing/revoked/foreign authority.

- [x] T005 [US1] Write permission, prerequisites, direct Action/route, administrator bypass and cross-company tests in tests/Feature/Courses/CoursePreviewAccessTest.php and tests/Feature/Platform/SharedCoursePreviewTest.php.
- [x] T006 [US1] Implement serialized active-link generation/retrieval in app/Actions/Courses/GenerateCoursePreviewLink.php and app/Services/Courses/CoursePreviewAuthority.php.
- [x] T007 [US1] Implement capability eligibility and exact composed/legacy content projection in app/Services/Courses/PublicPreviewResolver.php and app/Services/Courses/CoursePreviewProjection.php.
- [x] T008 [US1] Add authenticated generation/read endpoints and public overview/item routes in routes/web.php and app/Http/Controllers/CoursePreviewController.php with preview middleware under app/Http/Middleware.
- [x] T009 [US1] Add shared link controls to company/platform course show and editor views under resources/views/components/courses and resources/views/components/platform/shared-courses; protect credential hydration.
- [x] T010 [US1] Add public reader/layout and translated copy in resources/views/course-preview and lang/pt_BR.json with pt-BR default, explicit locale switch, mobile and keyboard support.

## Phase 4: US2 — Expire and regenerate (P1)

Goal: exact 168-hour expiry, fresh generation after expiration, no sliding lifetime. Independent test: frozen-clock boundary and old/new link comparison.

- [x] T011 [US2] Write expiry, renewal, live saved edits and publication/archive/discard tests in tests/Feature/Courses/CoursePreviewLifecycleTest.php.
- [x] T012 [US2] Complete generation lifecycle and friendly unavailable states in app/Actions/Courses/GenerateCoursePreviewLink.php, app/Services/Courses/PublicPreviewResolver.php and resources/views/course-preview.
- [x] T013 [US2] Prove first-generation and publication races using isolated PostgreSQL and independent processes in tests/Feature/Courses/CoursePreviewConcurrencyTest.php.

## Phase 5: US3 — Review without training evidence (P2)

Goal: view current text, playable media and read-only questions without compliance changes. Independent test: browse/play and compare evidence tables; test exact media expiry and item tampering.

- [x] T014 [US3] Write read-only assessment, no-evidence, payload sanitization, provider failure and media-boundary tests in tests/Feature/Courses/CoursePreviewContentTest.php and tests/Feature/Video/CoursePreviewPlaybackTest.php.
- [x] T015 [US3] Add optional absolute playback expiry in app/Contracts/VideoProvider.php, app/Services/Video/CloudflareStreamProvider.php and app/Services/Video/FakeVideoProvider.php preserving existing callers.
- [x] T016 [US3] Implement preview-only playback authorization and signed local streaming in app/Services/Courses/PreviewPlaybackService.php, app/Http/Controllers/CoursePreviewController.php and routes/web.php; recheck after vendor latency, never redirect public fake playback to tenant-bound dev routes.
- [x] T017 [US3] Add isolated player renewal and static assessment rendering under resources/js and resources/views/course-preview; no training event/answer calls.

## Phase 6: Cross-cutting verification and delivery

- [x] T018 Verify response/cache/referrer headers, token serialization/log confidentiality, unrelated inactive-company sessions and locale failures in tests/Feature/Courses/CoursePreviewAccessTest.php and affected middleware.
- [x] T019 Wire composer.json scripts.verify to existing required checks; run focused tests, meaningful regressions and canonical composer verification; record evidence in specs/003-public-draft-preview/verification.md.
- [x] T020 [P] Independent Code Review at stable diff; record findings in .toscanini/runtime/runs/public-draft-preview-20260905/finding-ledger.json.
- [x] T021 [P] Independent Test Analyst at same stable diff; record acceptance-to-test evidence in specs/003-public-draft-preview/verification.md.
- [ ] T022 Execute safe real UI/API QA matrix from specs/003-public-draft-preview/quickstart.md after source gates approve; capture redacted evidence.
- [ ] T023 Final independent architecture/design alignment and whole-scope Code Review after QA; close findings by impact and run contract-aware gate plus toscanini verify.

## Dependencies and parallel examples

T001–T002 precede all application edits. T003–T004 precede US1. US2 extends generation and resolution; US3 extends the public reader. All stories converge before T018–T019. T020 and T021 run independently at one stable checkpoint; T022 waits for both; T023 follows QA. With the one-Worker rule, production tasks remain sequential even where files differ. Per-story read-only inspection can run concurrently with Worker implementation (US1 authority evidence, US2 race evidence, US3 provider evidence) without another production editor.

## Implementation strategy

Deliver US1 as the first demonstrable slice, then exact lifecycle and media/assessment. Finish all stories before declaring completion. No partial MVP is a substitute for the approved scope. Mark only actually verified tasks complete; unavailable gates stay open. No Spec Kit extension hooks configured.
