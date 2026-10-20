# Independent design review — runtime round2

Verdict: **BLOCKED**. Run `20261020-account-owned-invitations`; scope `account-owned-invitations-v1`; checkpoint `account-owned-invitations-v1-remediation2-9a02b0261bbf387d`. Independent/fresh, phase QA, round2. Parent transcribed the specialist's terminal report without converting limits into approval. Specialist did not edit report/source/tests; actual started/terminal events are retained in both repositories.

## Findings

| ID | Severity/classification | Evidence and approved basis | Required outcome |
| --- | --- | --- | --- |
| DSR-01 | Medium, implementation omission | Account company-users initial screen shows Synchronize invitations without explaining status-only/no-email behavior; accessible description points to an empty status region. Copy appears only after dispatch. `resources/views/components/company-users/⚡index.blade.php:58–85`. Approved design sync-action bullet, FR-014, SC-08, AC-02/05. | Explain before activation that synchronization checks invitation status without sending email. |
| DSR-02 | Medium, implementation omission | Account Invitation card and directory invitation cell render state but not last successful verification date/age. Generic Communication timing is not invitation verification timing. `resources/views/components/company-users/⚡show.blade.php:127`, index line124, reconciliation line234. Approved design preservation/age bullet, FR-010/014, SC-08/16, AC-02/03. Populated state assessed from source, not runtime. | Expose the last successful invitation verification date/age, preserving it alongside current failure feedback. |
| DSR-03 | High, validation evidence unavailable | South fixture has no communications/current invitations; read-only assignment prohibited sync/send/retry, queue/fault/fixture mutations. Queued/running/completed/partial/exhausted, projection failure, retained verified info, retry focus and polling announcements were unexecuted. Approved design requires these states, SC-07/08/18/23, AC-03/05. This is an evidence blocker, not proof of an implementation defect. | Provide safe representative states and observe their actual browser presentation before design approval. |

## Executed observations

- Styled Account company/products, people list, person detail and Communication screens.
- Account390px table scroll stays inside its container; person cards and Communication empty state reflow/readable.
- Compliance invitation controls at wide/390px, labelled filters and truthful filtered empty state.
- Visible keyboard focus: Account Register person received petrol3px outline through Tab.
- Account viewer hides sync/register. Compliance viewer hides sync/invite/import/register/selection.
- Polite live regions/loading/disabled bindings exist in source; dynamic behavior was not verified.

The private server's missing static styles were corrected in disposable runtime only and are not a product finding. Browser sessions were serialized because same-origin tabs share cookies. Screenshots were inspected through CUA but not saved as files. All four design-created tabs closed and viewport reset. The report does not certify unexecuted dynamic states. Both terminal events report three findings. No production/test/configuration/.env edits or production/provider calls occurred.
