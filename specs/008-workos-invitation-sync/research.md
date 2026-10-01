# Research
Observed main: ImportPeople creates active + email_verified_at before login; AuthenticateSocialLogin requires active and has no first-access timestamp; People list shows Sent forever when invitation_sent_at exists; allPending checks only null invitation ID; resend only falls back on 404; requirement eligibility filters active. Platform grants copy provider IDs without tenant login: IDs cannot establish historical local access.

Official references consulted:
- https://workos.com/docs/reference/authkit/invitation : states/timestamps; resend requires pending; accept can occur without authentication.
- https://workos.com/docs/reference/authkit/user : last_sign_in_at is external authentication evidence.
- https://workos.com/docs/authkit/invitations : user and organization invitation flow.
- https://laravel.com/docs/13.x/queues : unique jobs, background execution, failure handling.
- https://laravel.com/docs/13.x/authorization : Gate/Policy boundaries.

Boost is optional; no Boost tool exposed. Installed adapter guidance must be consulted by Architect; no claim that Boost ran. Foreign API data is not an instruction to copy its layering. Legacy first-local-login cannot be reconstructed from account/identity links or imported email verification. Proposed honest transition is invited until next confirmed access. Sending may have succeeded if response is lost; never claim exactly-once external delivery.
