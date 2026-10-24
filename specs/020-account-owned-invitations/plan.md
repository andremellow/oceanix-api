# Implementation plan proposal

## Preparation only
Both targets inspected: Laravel13.26.1, Livewire4.4.1, Flux2.17.0, Sanctum4.3.3; PHP8.4.14 CLI and Node24.19.0 available. Boost not installed and no Boost tool exposed; official Laravel13 guidance must be consulted by Architect. Existing actions/services and durable HTTP operations are the design baseline, not permission to retain incorrect behavior. Constitution file is an unfilled template; repository AGENTS.md and confirmed user decisions apply. No Spec Kit extension hooks exist. Existing feature020 discovery is continued; no new branch required for documents.

## Ordered work after approval
1. Add system-wide service identity semantics in both products, retaining actor/company checks; update token setup guidance. Migration must preserve existing credentials without inventing wildcard list semantics.
2. Replace import's product dispatch/receipt dependency with explicit local baseline association and safe recovery handling of old import operations. Keep WorkOS read pagination and exact membership checks.
3. Define and implement deterministic existing-person mapping/adoption using provider identity and company binding, including imported Account records. No remote person creation or access update during mapping.
4. Centralize every operational invitation/registration path in Account, preserving Compliance AuthKit login and rechecking authorization of queued human intent.
5. Unify Account UI and product-origin status observation under a no-send action; add durable HTTP delivery of versioned invitation snapshots to Compliance. Preserve provider/observation success independently from projection delivery failures.
6. Preserve/extend existing person and Communication status/retry UI with source-specific safe failures; reuse design system and translations. Do not add a new administrative product.
7. Cutover/runbook and legacy queue handling; planned tests/QA from scenarios.md. Only one Worker across both repos. Full independent CodeReview/TestAnalyst at stable checkpoint, executable QA, Architect conformance, canonical verification and completion gate.

## Verification strategy
Pest + isolated SQLite for request/Action/persistence boundaries, fakeWorkOS/all product HTTP, PostgreSQL disposable concurrency checks where locks matter, browser evidence for changed controls, actual cross-service private HTTP API/queue fixture for operation retries/projections. No real WorkOS/provider mutations or production data. New regression tests should fail for old behavior before fixes; existing tests remain positive controls. Scenario table names intended test files and assertions; exact file boundary is finalized by Architect before approval. All proposed tests NOT_RUN.

## Execution readiness
Documentation authoring is ready. Implementation is NOT_READY until approved architecture contracts and exact file boundary, representative private cutover fixtures and paired API/worker harness have been proven. No new packages or broker are proposed. Production credentials are not requested and are not required for private fake-provider QA. Existing unrelated dirty files must remain untouched. Canonical composer verify applies to each repository after implementation; do not claim it ran for this preparation-only turn.

## Non-goals
Events/broker/pubsub, SDK extraction, generic synchronization of arbitrary provider users, training changes, production rollout, bulk access changes, raw secret logging and unrelated tooling repairs.

## Constitution check (pre/post design)

No ratified constitution rules are present: .specify/memory/constitution.md is the untouched starter template. AGENTS.md supplies architecture, thin components, service/Action placement, English-first source, authorization, retention, SpecKit/Toscanini and approval gates. User explicitly authorized preparation only. Before authoring: compliant; post-design: check architect artifact and exact inventory, keep owner approval/readiness PENDING. No implicit waiver or fake approval.

## Technical context

Language PHP8.3+ project constraint, observed CLI8.4.14; Laravel13.26.1, Livewire4.4.1, Flux2.17.0 and Sanctum4.3.3 installed in both. Storage PostgreSQL production, private SQLite tests plus disposable PostgreSQL concurrency QA. Existing Laravel queues, scheduled due-work recovery, HTTP clients and operation/target receipts; no new dependency or network broker. Operational users currently few; paginated/chunked population supported without arbitrary performance claims. Target independent web applications, LaravelCloud production configured by operator only after approval/implementation. Architecture and exact classes/paths belong to architecture.md and architecture-paths.json.

## Phase artifacts

research.md, data-model.md, contracts/ and quickstart.md are Architect-authored details following this proposal. Task generation intentionally waits for owner approval; no tasks.md or Worker dispatch yet. SpecKit setup-plan and paths-only prerequisite ran successfully for existing feature020; both hook sets absent.
