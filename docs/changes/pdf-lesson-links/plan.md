# Implementation planning input

Read request.md as the scoped proposal. Architecture is authored before production work.

Observed baseline: Laravel 13.26.1 installed, PHP 8.4.14, Node 20.20.2. Unified `EditorCoordinator`, its three `Contexts`, `course-editor/root.blade.php`, Flux editor extensions and `content-editor.js` own editor behavior. Existing content images are public and therefore are not a suitable delivery mechanism for the owner's private-PDF requirement. `LessonContentSanitizer` already retains anchor href/target/rel and enforces noopener/noreferrer. Current learner access uses assignment authorization and `includesLesson`; previews have existing scoped authority/resolution services.

Architect should select the smallest framework-native private PDF model/action/read service/controller, preserving existing editor operation guards, stable record identity, staged save semantics and HTML sanitation. Bind a document reference to the actual rendered lesson/version rather than treating an opaque identifier as permission. Specify shared-module copying and authorized preview resolution explicitly. Avoid broad refactors and new unrelated permissions.

Readiness probes must verify installed dependencies, available Node runtime compatible with Vite, focused PHP execution, build and safe browser execution before Worker dispatch. Do not edit `.env`. Review and QA must use only request.md's frozen scenarios and direct diff regressions. Production implementation is pending direct owner approval of the concrete architecture and contract required by the repository workflow.
