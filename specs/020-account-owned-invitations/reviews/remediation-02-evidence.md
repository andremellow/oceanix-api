# Remediation round 2 — Worker evidence

Frozen scope `account-owned-invitations-v1`; original architecture unchanged. Delta starts at Account `33375e42da9f5c33b2761686229cbfcc90787559` and Compliance `5ff9d2089115dee98f9d8c18ec0819289d207ca1`. This final consolidated batch addresses CR-06, CR-07 and the remaining Compliance TA-01 source-binding proof. It does not certify original findings or replace directed review.

## Candidate corrections

**CR-06:** A manual alias retry authorization is consumed atomically under company/canonical/reference locks. Only a still-terminal canonical operation is rearmed. Consuming a marker while canonical work is already active prevents that old marker from authorizing a later exhausted generation. Confirmed targets remain untouched. The CR-02 HTTP regression retains its original normal/immediate-recovery cases and adds canonical-first/reference-first schedules: seven actual failed delivery attempts, terminal state/attempt count, repeated reference/canonical execution with no additional delivery or provider read, then successful recovery only after a new authorized manual retry. Both schedules failed before correction; all four variants pass afterward (136 assertions).

**CR-07:** Recovering an already-applied same-generation invitation finalizes the immutable request/attempt without writing the historical send snapshot into the person again. Pending receipt datasets seed newer accepted, expired and revoked same-invitation sequence-2 projections. Each compares every persisted person attribute, including accepted history, access, generation, sequence, hash and verified timestamp. All three new datasets failed before correction through stale projection overwrite; the complete recovery test file passes afterward (7/67). Original missing-receipt/queued/sending cases and no-repeat assertions remain.

**TA-01:** The valid persisted source callback positive control and existing forged outer-envelope/revocation controls remain. Six additional combinations persist a mapping hash exactly equal to its submitted envelope while the referenced existing source independently has wrong subject, hash, actor, company, person or kind. All outer principal/binding/actor/permission fields remain valid. Each denies without changing operation records. These reach the source-relation predicate after the earlier mapping hash comparison, rather than passing because of an unrelated mismatch. Complete file: 8/55. No production policy change was needed.

**T029 nonblocking clarification:** Both approved production integration runbooks now leave deprecated company_uuids empty, preserve reverse operation capabilities and exact company/binding/current-human checks, and describe import as an independent Account-local baseline plus unverified association with no Compliance receipt/call requirement. Earlier diagnostic text no longer refers to a remote Compliance migration. Existing protocol2 deployment/readiness instructions remain authoritative. No new configuration or behavior was introduced.

## Executed checks

After scoped formatting:

- Account complete `InvitationObservationDeliveryTest.php`, `LegacyInvitationCutoverTest.php`, `Integration/SystemPrincipalTest.php`: 23 passed / 292 assertions.
- Compliance complete `AccountInvitationTest.php`, `ControlPlane/SystemPrincipalTest.php`, `WorkosInvitationSyncTest.php`: 39 passed / 221 assertions.
- Explicit approved-path Pint `--test` passed both repositories; `git diff --check` passed.
- No whole canonical suite was executed by Worker. Source/tests frozen for the orchestrator's affected deterministic gates.

Raw terminal results are preserved alongside this report: `remediation2-cr06-red.log`, `remediation2-cr06-green.log`, `remediation2-cr07-red.log`, `remediation2-cr07-green.log`, `remediation2-ta01.log`, `remediation2-a-focused.log`, `remediation2-c-focused.log`. Color escapes were removed only. Early fixture construction mistakes were corrected before the causal red results (schema field name / closure variable capture); they are not represented as product defects.

No new path in the source/test inventory, tooling change, provider/live database call, environment-file edit, publication or new QA scenario. Earlier PostgreSQL contention evidence and retained TA02–08 approvals remain available; no PostgreSQL-dependent code changed in this batch. Final corrective commits/checkpoint and canonical outcomes are recorded below after actual execution. All finding closure remains pending isolated directed verification; no independent approval is claimed.


## Final corrective checkpoint

Both orchestrator-run canonical commands exited zero. Account:440 passed,7 skipped,3016 assertions;43 browser/658;build/Pint pass. Compliance:1157 passed,18 skipped,6984 assertions;editorJS30,PDFJS4;browser79/811+40/800+2/10+4/48+12/207;previewJS25;build/Pint pass. Complete raw canonical logs are preserved beside this report as remediation2-account-canonical.log and remediation2-compliance-canonical.log.

Account corrective commit `8cb3a44f330c4675f11acf1ee3a6fbbde51ea6f2` changes3approved paths against33375e42; Compliance `462f6b5604268904393fa8aebee2bb771ca4beaf` changes4approved paths against5ff9d208. Immutable pair checkpoint `account-owned-invitations-v1-remediation2-9a02b0261bbf387d`; manifest `remediation-02-checkpoint.json` SHA256 `47d28cc268b86dc8874b3353a0c4391c14c8eb806c44e768af20c7264cfb68c9` contains exact paths/bases/diff hashes. Prior checkpoint artifacts remain unchanged.

No push or PR. Source/test working trees are clean; unrelated original language/tooling/specification edits remain outside these commits. All independent/directed findings decisions remain pending; Worker completion means this authorized correction batch is implemented and checked, not gate approval.
