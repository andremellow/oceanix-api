# Operator contracts
- People list remains protected by PeopleView. New sync ability PeopleSyncWorkos requires PeopleView, applies to current company only, reauthorizes initiation and execution, and respects user/profile revocation.
- Sync action queues a company-scoped run and returns its status, counts and errors without sending mail.
- Invitation-state filters distinguish pending/accepted/expired/revoked/not-invited/unverified. No-local-access is separate.
- Existing PeopleInvite capability handles selected and default-pending recovery; current provider state is checked at execution. Revoked requires explicit selection; accepted/current member and suspended/terminated recipients are skipped.
- Callback and authorized platform/tenant entry write real local server access evidence only after successful access validation; no change to access denial for blocked statuses/accounts.
- All assignment recipient statuses allowed; all other course/target/version/recurrence constraints retained.
