# Specification gate policy clarification

The repository Spec Kit instructions explicitly say: “Do not start Three Amigos bots or add a mandatory specialist review checkpoint.” The approved task sequence T031–T034 includes independent Code Review, Test Analyst, runtime QA, implemented-design review and architecture conformance, with product-owner-approved specification/clarification/plan. It does not include a separate Specification Reviewer.

The contract now explicitly records `specification.independentReviewRequired=false` so the installed optional-gate default does not invent an additional checkpoint. The specification remains required and approved; its hash, owner approval, clarification, plan, AC/INV, QA matrix and all planned independent implementation gates are unchanged. This is not a specification waiver or a missing-review approval.
