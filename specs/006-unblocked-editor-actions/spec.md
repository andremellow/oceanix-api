# Feature Specification: Unblocked Editor Actions

**Feature Branch**: ``codex/discard-module-drafts`
**Created**: 2026-09-11
**Status**: Approved by direct product-owner instruction
**Input**: Authors must be able to keep editing and use editor actions without being blocked merely because some values are not yet saved. Required TDD scenario: add an answer while clean, type in that new answer, then add another answer while the first typed value remains staged and intact.

## User Scenarios & Testing

### User Story 1 - Continue building an assessment without interruption (Priority: P1)

As an authorized course author, I can add an answer, type its text, and immediately add another answer without first saving or losing what I typed.

**Why this priority**: This is the exact reported blocker and prevents normal assessment authoring.

**Independent Test**: Start from a clean question, add one answer, type a distinguishable value without saving, add a second answer, verify both answers remain visible, verify only the structural additions exist in persistence before Save, then Save/reload and verify the typed value.

**Acceptance Scenarios**:

1. **Given** a clean editable question, **When** the author adds an answer, **Then** the answer appears and receives stable identity without requiring Save.
2. **Given** the new answer contains unsaved text, **When** the author adds another answer, **Then** the action is available, the second answer appears, the first unsaved text remains visible, and that text has not been persisted.
3. **Given** the author has continued editing after structural actions, **When** Save is selected, **Then** all retained authored values persist atomically and survive reload.

### User Story 2 - Continue any draft-editing operation while values are staged (Priority: P2)

As an authorized author, I can add, remove, reorder, compose, or use rich-content media controls while unrelated authored values remain unsaved.

**Why this priority**: A shared editor must not reintroduce the same interruption at another structural or media control.

**Independent Test**: In each editor context stage distinct values at multiple nested levels, execute each applicable editing operation, and verify the operation's bounded persistence plus exact retention through final Save/reload.

**Acceptance Scenarios**:

1. **Given** unsaved values on surviving records, **When** an authorized add or reorder succeeds, **Then** the values follow stable identity and remain unsaved.
2. **Given** unsaved values outside a confirmed removal target, **When** the target is removed, **Then** only that target's staged subtree is discarded.
3. **Given** unsaved content, **When** an applicable image or video editing flow runs, **Then** the content remains staged and the media operation stays bound to the correct record.

### User Story 3 - Preserve truthful safety boundaries (Priority: P3)

As an author, I receive blocking only when the operation cannot be performed safely, with a specific explanation and no hidden retry or data loss.

**Why this priority**: Removing the dirty-only guard must not weaken authorization, concurrency, or upload guarantees.

**Independent Test**: Exercise stale revision, permission revocation, unknown network outcome, conflicting operation, and applicable active upload with exact before/after snapshots.

**Acceptance Scenarios**:

1. **Given** only unsaved authored values, **When** an editing action is selected, **Then** dirty state is not a blocker.
2. **Given** a stale, unauthorized, unknown, or conflicting state, **When** an editing action is selected, **Then** it fails closed with retained local values and cause-specific recovery.

### Edge Cases

- A reorder changes visible indices while stable identities and their staged values remain paired.
- A confirmed removal drops only the removed identity and descendants; a declined or failed removal drops nothing.
- An edit typed after an operation request starts but before its response returns must win over older request-start state.
- A malformed, duplicated, cross-parent, or temporary identity must never receive another record's staged value.
- A same-record active upload may block an incompatible media/removal operation; an unrelated record remains editable.

## Requirements

### Functional Requirements

- **FR-001**: Unsaved values alone MUST NOT block any authorized draft-editing control.
- **FR-002**: Adding an answer after typing unsaved text into a newly added answer MUST retain that text and add the next answer.
- **FR-003**: An immediate structural or media operation MUST persist only its named effect and MUST NOT persist staged authored values.
- **FR-004**: Staged values MUST be preserved by stable identity and ancestry across canonical refresh and reorder.
- **FR-005**: Explicit Save MUST use refreshed canonical revisions and persist the retained authored graph atomically.
- **FR-006**: Permission, lifecycle, stale conflict, unknown network outcome, conflicting operation, destructive confirmation, and applicable upload protections MUST remain enforced.
- **FR-007**: Published content, historical evidence, lineage, ownership isolation, and private media boundaries MUST remain unchanged.
- **FR-008**: Tests MUST demonstrate the reported failure before production correction and then pass in company, shared-course, and standalone-module contexts.

### Key Entities

- **Canonical draft graph**: Persisted course/version/record/question/option identities, order, revisions, and media associations.
- **Staged authored state**: Unsaved whitelisted field values and validation provenance associated with stable canonical identities.
- **Immediate editing operation**: One authorized and revision-checked structural or media change that excludes authored-field persistence.

## Success Criteria

### Measurable Outcomes

- **SC-001**: The exact add–type–add scenario completes without a Save between actions in all three editor contexts.
- **SC-002**: Before explicit Save, 100% of staged authored values remain absent from persistence while the intended structural change is present.
- **SC-003**: After explicit Save and reload, 100% of retained staged values and intended structure match what the author saw.
- **SC-004**: Every frozen failure scenario produces zero unintended writes and retains all unrelated staged values.

## Assumptions

- “Anything without being blocked” means draft-editing operations; publishing and saved-draft preview still require saved canonical content.
- Existing routes, permissions, actions, providers, schema, and publication/history rules remain in force.
- The current unified editor remains the implementation base; removed duplicate editors are not restored.
