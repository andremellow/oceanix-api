# Architecture: [feature]

## 1. Context, references and decision scope

Reference specification, plan, acceptance criteria (`AC-*`), invariants (`INV-*`) and scenarios (`SC-*`); do not copy their contents. List affected repositories, constraints, preserved behavior and non-goals.

| Convention / decision | Repository or reference-project evidence | Existing convention / new feature-local / new repository-wide | Why needed |
| --- | --- | --- | --- |

Inspect actual sources. In a sparse application, state what is new instead of presenting a preference as established practice.

### Framework suitability and reference assessment

| Target repository / framework / installed version | Official and adapter guidance actually consulted | Reference purpose and patterns accepted/rejected | Framework-native option and tradeoffs | Alignment / material conflict / specific owner decision |
| --- | --- | --- | --- | --- |

Complete one row per affected repository. For Laravel, consult installed Boost guidance and relevant documentation tools when available; record an official-documentation fallback if unavailable. For Flutter or another stack, assess that stack's own guidance. Reference API behavior/contracts do not imply adopting its internal class structure. Distinguish target conventions, external reference patterns and new decisions. Do not silently copy a materially conflicting pattern or silently migrate the target's established architecture: explain alternatives and obtain the owner's decision. Do not claim the framework mandates a preference it merely recommends.

Copy the assessment to `architecture.frameworkAlignment` in the execution contract. `unresolved` blocks approval; `approved-deviation` requires a specific owner decision reference. `aligned` still requires consulted guidance and an explicit assessment of references (or a statement that none were supplied). For framework-free code, identify that fact and cite the applicable runtime/project guidance.

Record TDD/test-first versus another testing strategy separately, following explicit preferences or asking only when the choice is material and unresolved.

## 2. Architectural style

Name the selected Laravel/application architecture and explain why it is the simplest sufficient design. Explain any extra layering by a concrete requirement. Adapt Laravel-specific concepts to the project's native stack when applicable.

## 3. Directory and module structure

Provide a directory tree for each affected repository, with principal class names or categories. Identify feature-local versus repository-wide structure and the existing files to extend.

## 4. Class responsibilities and non-use

For every type below, state the responsible class/location or explain why it is not used. Do not introduce a class merely to fill the table.

| Type | Responsibility / principal class | When not used |
| --- | --- | --- |
| Livewire component | | |
| Controller | | |
| Form Request | | |
| API Resource | | |
| Action | | |
| Service / external client | | |
| Repository | | |
| Query Object | | |
| DTO / Value Object | | |
| Model / persistence record | | |
| Policy / authorization service | | |
| Job / command | | |
| Event / listener | | |

## 5. Invocation, naming and query placement

Specify Action `handle`, `execute` or `__invoke`; class naming; whether Controllers are invokable; persistence-model naming when relevant. Avoid Helper, Manager, Utils or vague Service classes without a precise external-boundary responsibility.

State exactly where Eloquent/native persistence queries may exist and where they are forbidden. Decide whether Actions query directly, a Repository is needed, or a focused Query Object is justified. Do not wrap Eloquent method-for-method in another layer.

## 6. Dependency direction

Include a small dependency diagram showing actual selected boundaries, permitted imports/calls, and prohibited dependencies between applicable Presentation, Application, Domain, Infrastructure, native adapters and external integrations. Do not create layers merely to populate the diagram.

## 7. Cross-cutting ownership and system contracts

| Concern | Owning layer / class | Contract and constraints, or N/A reason |
| --- | --- | --- |
| Request validation | | |
| Authorization / security boundary | | |
| Tenant isolation / data ownership | | |
| Mapping | | |
| Transactions and locks / concurrency | | |
| Persistence | | |
| External calls and timeouts / API contracts | | |
| Error and outcome translation / failure behavior | | |
| Serialization | | |
| Configuration | | |
| Logging and telemetry | | |
| Retry behavior | | |
| Migrations and rollback | | |

Keep protocol and security details that materially constrain implementation. Reference invariant IDs instead of duplicating scenario matrices.

## 8. Concrete execution flows

Show short end-to-end flows for important use cases: entry point → named participating classes → persistence/external boundary → outcome. Include concise pseudocode/PHP only where it resolves implementation ambiguity; this is not a tutorial.

## 9. Test seams

Identify dependencies to fake, real boundaries requiring integration evidence, and which architecture invariants those checks demonstrate. Reference the specification/test artifacts for exhaustive examples and QA matrices.

## 10. Author verdict and classified conditions

Author readiness: `APPROVED | APPROVED_WITH_CONDITIONS | BLOCKED`.

| Condition / open decision | Category | AC/INV or architecture section | Owner and next action | Could materially change architecture? |
| --- | --- | --- | --- | --- |

Categories: product-decision, architecture-decision, operational-dependency, implementation-task, spike. Only unresolved decisions that materially change architecture block approval. Missing migrations/routes/classes/adapters/tests are tasks; credentials/licensed dependencies are operational conditions; device/integration verification is a spike/task unless its outcome changes the architecture.

## 11. Product-owner approval

Record the explicit decision reference in execution-contract.json, binding it to this artifact's SHA-256 and frozen scope ID. The author verdict is not product-owner approval. Approval permits task generation and implementation directly.

After implementation, the Architect writes a separate conformance report comparing this approved artifact, specification, contract, and final implementation/tests. That report cites this document's sections or AC/INV IDs, reports deviations and non-blocking follow-ups, and cannot redesign this architecture.
