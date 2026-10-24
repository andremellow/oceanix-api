# Shared Oceanix platform package

Version: 5. Updated: 2026-10-21. Status: required shared core extracted into one local Composer package; final validation is recorded in extraction-status.md.

The package is `oceanix/platform`, sourced from `../support/sdk` relative to Compliance (`~/Code/Oceanix/support/sdk`). Compliance is its first consumer. WorkOS authentication, bidirectional Account integration, tenant isolation, product access, people/invitations, profiles/permission mechanics and runtime evidence are shared. Domain catalogs/Policies, training data and domain presentation extensions remain with the consumer. Shared login/people/profile screens and their routes now belong to the SDK.

## Reading order

1. [Confirmed scope](scope.md).
2. [Extraction status and validation](extraction-status.md).
3. [SDK installation and API](../../../../support/sdk/README.md).
4. [Preservation rules](contracts-and-invariants.md).
5. [Inventory and host adapters](inventory.md).
6. [Package boundaries](package-proposal.md).
7. [Extraction plan](extraction-plan.md).
8. [Continuation handoff](next-agent-prompt.md).
9. [Source manifest](source-manifest.json).

No boilerplate or redesign of the existing application layouts/design system. No external Account changes, publication, deployment or production migration. Existing working-tree changes outside this task were preserved.
