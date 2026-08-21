# Validation Quickstart

1. Recreate and seed the disposable database.
2. Bootstrap a platform administrator through the configured admin email and local WorkOS bypass.
3. Visit `/platform`; confirm aggregate cards and company directory.
4. Create a second company and confirm its UUID, slug and baseline access profiles.
5. Link one account to two company people, switch company and confirm the audited `/c/{slug}/dashboard` context.
6. Confirm a company administrator receives 403 on `/platform`.
7. Link one Account to people in two companies and verify separate permissions and histories.
8. Attempt login with an email absent from the selected company and confirm zero people are created.

```bash
composer test
./vendor/bin/pint
npm run build
```

Validated on 2026-08-21: 241 Pest tests (574 assertions), Pint, and the production Vite build passed.
