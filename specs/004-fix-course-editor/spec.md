# Feature Specification: Reliable Course Authoring

**Feature Branch**: `codex/discard-module-drafts`

**Created**: 2026-09-09

**Status**: Approved — amended 2026-09-10

**Input**: Repair the company course creation and editing journey so authored questions and lessons cannot silently disappear, course codes validate correctly per company, required lesson rules remain configurable, autosave and media status are trustworthy, publication defaults are safe, ordering is complete, and form controls are accessible.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Preserve authored training content (Priority: P1)

As a training administrator, I can clearly choose either reusable modules or direct legacy lessons for a draft, without creating content that preview, publication, or employee training silently ignores.

**Why this priority**: Silent omission can publish an incomplete compliance course while leaving the editor looking correct.

**Independent Test**: Create a draft with direct lessons and questions, attempt to add a reusable module, and verify the system prevents an ambiguous mixed composition while preserving every existing question.

**Acceptance Scenarios**:

1. **Given** a draft with direct lessons or questions, **When** an administrator tries to add its first reusable module, **Then** the system explains that the draft must use one composition mode and leaves all content unchanged.
2. **Given** a draft composed from reusable modules, **When** the administrator views the editor, **Then** direct-lesson creation is unavailable and the module content remains the only published composition.
3. **Given** a pre-existing draft that already contains both composition types, **When** the editor opens, **Then** publication is blocked with a specific resolution message and neither content set is deleted.

---

### User Story 2 - Configure and save lesson rules reliably (Priority: P1)

As a training administrator, I can set the watch threshold, passing score, questions, answers, and attempts while receiving accurate saving and validation feedback.

**Why this priority**: These values govern when an employee may take and pass an assessment.

**Independent Test**: Edit every lesson rule, simulate success and validation failure, navigate away only after persistence, and verify the employee-facing behavior uses the saved values.

**Acceptance Scenarios**:

1. **Given** an editable direct lesson, **When** the administrator changes its watch threshold from 90% to 95%, **Then** the value saves inline for tracking and reporting while the assessment remains available immediately when the lesson opens.
2. **Given** a save request is pending or fails, **When** the editor reports state, **Then** it never reports the change as saved and gives the administrator an actionable status.
3. **Given** a video is uploading, processing, ready, or failed, **When** the lesson is displayed, **Then** its text status is visible next to that lesson.

---

### User Story 3 - Create and publish safely (Priority: P1)

As a training administrator, I can create a course with a company-scoped permanent code and publish a new version without accidentally restarting in-progress training.

**Why this priority**: Incorrect validation blocks legitimate course creation, while an unsafe publication default can discard learner progress.

**Independent Test**: Create equal normalized codes in different companies, reject a normalized duplicate within one company inline, and publish a new version without replacing open assignments unless explicitly selected.

**Acceptance Scenarios**:

1. **Given** another company already owns code `HUET-01`, **When** the current company creates ` huet-01 `, **Then** its course is created with normalized code `HUET-01`.
2. **Given** the current company already owns `HUET-01`, **When** an administrator submits a case- or whitespace-variant duplicate, **Then** the modal shows an inline validation error and creates no course.
3. **Given** a course has open assignments, including in-progress work, **When** a new version is opened for publication, **Then** existing assignments remain on their current version by default and replacement requires an explicit choice.
4. **Given** a course was created, **When** its draft editor opens, **Then** its permanent course code is readable but not editable.

---

### User Story 4 - Operate the editor accessibly and efficiently (Priority: P2)

As a keyboard, screen-reader, desktop, or mobile user, I can identify answer controls, reorder authored content, and complete creation or publication without duplicate actions or clipped controls.

**Why this priority**: Course authoring must remain understandable and operable for all administrators.

**Independent Test**: Use keyboard-only interaction and a narrow viewport to create questions, identify each answer, reorder supported items, and submit each modal once.

**Acceptance Scenarios**:

1. **Given** a question with several alternatives, **When** assistive technology reads the form, **Then** every answer field and correct-answer control has a unique label tied to its position.
2. **Given** multiple lessons, questions, or alternatives, **When** the administrator reorders them, **Then** the new order persists and appears in preview.
3. **Given** a create or publish request is running, **When** the administrator activates the action again, **Then** the control remains disabled and no duplicate operation occurs.
4. **Given** a narrow viewport, **When** the administrator uses creation, editing, media, and publication controls, **Then** the primary action stays visible without horizontal page overflow.

### Edge Cases

- A mixed legacy/module draft already stored before this repair must retain both data sets and provide a recoverable resolution path.
- Removing the last reusable module returns an otherwise empty draft to direct-lesson mode.
- A normalized course code collision must be handled as validation even when two requests race.
- Losing connectivity during a debounced content edit must leave a visible unsaved/error state.
- A failed or indefinitely processing video must not present a ready state or permit publication.
- Reordering the first or last item must not create duplicate or missing positions.
- Removing an answer must not silently leave an invalid single-choice question without clear validation.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: A company draft MUST use exactly one active composition mode: ordered reusable modules or ordered direct legacy lessons.
- **FR-002**: The system MUST prevent creating a new mixed composition and MUST preserve all content when rejecting the operation.
- **FR-003**: A pre-existing mixed draft MUST remain recoverable, MUST not publish, and MUST identify the conflict in the editor.
- **FR-004**: Preview, publication validation, published training, and editor messaging MUST agree on the active composition.
- **FR-005**: The direct lesson editor MUST expose a labelled watch-threshold percentage from 1 through 100 and explain that it is used for tracking and reporting and does not block assessment access.
- **FR-006**: Lesson video state MUST be visible as accessible text for uploading, processing, ready, and failed states.
- **FR-007**: Saving feedback MUST cover every persistent editor mutation and MUST distinguish unsaved, saving, saved, validation-error, and network-error states.
- **FR-008**: Navigation away from known unsaved work MUST require confirmation; successful persisted changes MUST not trigger that warning.
- **FR-009**: Course codes MUST be normalized before validation and MUST be unique within the owning company rather than globally.
- **FR-010**: A normalized same-company duplicate MUST produce an inline validation error, including when detected at the persistence boundary.
- **FR-011**: A course code MUST become read-only after course creation, consistently with its permanent identity.
- **FR-012**: Publication MUST keep existing open assignments on their current version by default; replacing them MUST require an explicit selection after impact is shown.
- **FR-013**: Each answer input and correct-answer selector MUST have a visible or programmatically associated unique label.
- **FR-014**: Administrators MUST be able to reorder direct lessons, questions, and alternatives, with persistent contiguous positions and keyboard-operable controls.
- **FR-015**: Creation, saving, media, and publication actions MUST disable their initiating control while running and expose a text loading state where noticeable.
- **FR-016**: Creation, editor, media, validation, empty, error, and publication states MUST remain usable without horizontal page overflow at a 320-pixel viewport.
- **FR-017**: Existing authorization, tenant isolation, immutable published versions, append-only evidence, and immediate assessment availability MUST remain unchanged.

### Key Entities

- **Course**: Permanent company-owned training identity, including its normalized immutable code and current published version.
- **Course Version**: Editable draft or immutable published edition with exactly one active composition mode.
- **Direct Lesson**: Legacy draft lesson containing content, tracking/reporting watch threshold, passing score, questions, and media.
- **Reusable Module Version**: Published company or shared content referenced in an ordered course composition.
- **Question and Answer Option**: Ordered assessment content with type, correct-answer selection, and attempt limit.
- **Training Assignment**: Historical obligation frozen to a specific course version; replacement is explicit rather than the publication default.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In 100% of tested drafts, every question visible as active editor content also appears in the corresponding preview and published training, or publication is explicitly blocked before omission.
- **SC-002**: Administrators can configure every direct-lesson rule and tracking threshold from the editor without using hidden state or developer tools.
- **SC-003**: All normalized duplicate-code scenarios return an inline result with zero partial courses created, while identical codes in two companies both succeed.
- **SC-004**: Zero in-progress assignments are replaced by the default publication path; replacement occurs only after explicit administrator selection.
- **SC-005**: Every persistent mutation tested reports an accurate final saved or error state, and no known unsaved edit is lost during guarded navigation.
- **SC-006**: All answer and ordering controls are keyboard operable, uniquely announced, and usable at a 320-pixel viewport without horizontal page overflow.
- **SC-007**: Existing course-authoring, permission, tenant-isolation, preview, publication, and employee-assessment regression suites remain green.

## Assumptions

- Direct legacy lessons and reusable modules are mutually exclusive composition modes because all current read and publication projections already select one mode rather than interleave both.
- Existing mixed drafts are exceptional migration states; this change blocks publication and preserves data rather than automatically converting or deleting content.
- Course code permanence starts immediately after successful creation, matching current product copy and the permanent-identity model.
- Existing open assignments staying on their frozen version is the safest default; administrators may still explicitly replace them.
- Per `docs/product-spec.md` §7 and the product-owner decision on 2026-09-10, assessment is available immediately when a lesson opens; watch threshold is tracking/reporting data only for this scope.
- Reordering may use accessible move controls in addition to pointer drag-and-drop, provided all three content levels are supported and persisted.
- No new external package, service, migration, or production access is required.
