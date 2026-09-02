# Platform Tasks API

Oceanix exposes a host-owned automation facade at `/api/platform/tasks/v1`. The package's generic
API remains disabled. This facade intentionally has no delete, restore, attachment, or media routes.

## Authentication

Generate a high-entropy key outside the application and configure only its SHA-256 hash plus the
canonical actor's email in the production environment. Do not put the plaintext key in config,
source control, shell history, URLs, or logs. For example, generate and hash it interactively:

```shell
read -rs TASKS_KEY
printf %s "$TASKS_KEY" | shasum -a 256
```

Set the resulting lowercase digest as `OCEANIX_TASKS_API_KEY_HASH` and set
`OCEANIX_TASKS_API_ACTOR_EMAIL` to an active user belonging to an active platform-administrator
account. The account and user are checked on every request, so revocation is immediate. Rotate by
replacing the configured hash.

If the deployment uses Laravel's configuration cache, update both environment values and rebuild
the cache (`php artisan config:cache`) as one deployment operation. The old key remains valid until
the application processes are using the rebuilt cache; verify the new key, then destroy the old
plaintext secret. A key rotation does not require changing application data.

Send the plaintext value in the `X-Tasks-Key` header. Prefer a client secret store or an environment
variable so it is not embedded in a command:

```shell
curl --fail-with-body --header "X-Tasks-Key: $TASKS_KEY" \
    --header 'Accept: application/json' \
    https://example.com/api/platform/tasks/v1/tasks
```

Validation failures use Laravel's standard JSON `422` response. Requests are limited to 60 per
minute per source IP, including invalid authentication attempts.

## Endpoints

- `GET /tasks`: filters `status`, `priority`, `assignee_id`, `task_type_id`, `tag_id`, `search`; pagination defaults to 25 and is capped at 100
- `GET /tasks/{task}`
- `POST /tasks`
- `PATCH /tasks/{task}` (including nullable `assignee_id`)
- `PUT /tasks/{task}/position` with `status` and one-based `position`
- `GET /tasks/{task}/comments`
- `POST /tasks/{task}/comments` with `body`
- `POST /tasks/{task}/resolve` (idempotent)
- `GET /meta`: task types, tags, eligible assignees, statuses, and priorities

All paths above are relative to `/api/platform/tasks/v1`.
