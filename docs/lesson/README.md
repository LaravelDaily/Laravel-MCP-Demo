# TeamBoard course lessons

Each lesson explains code that already exists at a Git tag. You do not build anything on camera: check out the lesson's end tag, run the listed commands, demo the behavior, then walk through the code and tests.

| Lesson | File | Starts at | Ends at |
| --- | --- | --- | --- |
| 00 | [Meet TeamBoard](00-meet-teamboard.md) | starter kit | `course/00-teamboard-baseline` |
| 01 | [Your first authenticated MCP tool](01-first-authenticated-tool.md) | `course/00-teamboard-baseline` | `course/01-first-tool` |
| 02 | [Useful read tools for agents](02-read-tools.md) | `course/01-first-tool` | `course/02-read-tools` |
| 03 | [Let the agent create and update work](03-write-tools.md) | `course/02-read-tools` | `course/03-write-tools` |
| 04 | [Permissions, tenant isolation, and dangerous tools](04-permissions.md) | `course/03-write-tools` | `course/04-permissions` |
| 05 | [Resources and prompts: ship ready-made workflows](05-resources-prompts.md) | `course/04-permissions` | `course/05-resources-prompts` |
| 06 | [Production basics](06-production-basics.md) | `course/05-resources-prompts` | `course/06-production-basics` |

Lesson 07 (Passport OAuth) is recorded separately later.

## Preparing a lesson for recording

Record each lesson from its **end tag**, so the code on screen matches what the lesson explains. The simplest setup reuses the main folder and the `https://teamboard.test` URL:

```bash
git status                      # must be clean
git switch --detach course/03-write-tools   # the lesson's end tag
composer install
php artisan migrate:fresh --seed            # resets the LOCAL demo database
npm install && npm run build
php artisan config:clear
```

`migrate:fresh --seed` wipes the local database and recreates the demo data. That is what you want for recording (stable IDs, clean audit log), but only run it when nothing in the local database matters. Go back to the latest code afterwards with `git switch main`, then run the same commands again.

If you prefer to keep your main folder untouched, use a separate worktree with its own database and URL instead:

```bash
git worktree add ~/Herd/teamboard-lesson course/03-write-tools
cd ~/Herd/teamboard-lesson
cp ../teamboard/.env .env       # then set APP_URL=https://teamboard-lesson.test
touch database/database.sqlite
composer install && php artisan migrate --seed && npm install && npm run build
herd secure teamboard-lesson
```

With a worktree, replace `teamboard.test` with `teamboard-lesson.test` in every URL below.

## Demo data after a fresh seed

IDs are stable after `migrate:fresh --seed`, so the prompts below use them directly.

| Account (password `password`) | Memberships |
| --- | --- |
| Alice `alice@example.com` (ID 1) | Acme owner |
| Bob `bob@example.com` (ID 2) | Acme member |
| Charlie `charlie@example.com` (ID 3) | Acme viewer |
| Dana `dana@example.com` (ID 4) | Acme owner, Sandbox viewer |
| Eve `eve@example.com` (ID 5) | Globex owner |

| Project | ID | Workspace |
| --- | --- | --- |
| Website Redesign | 1 | Acme |
| Billing Improvements | 2 | Acme |
| Sandbox Experiments | 3 | Sandbox |
| Globex Payroll Platform | 4 | Globex |
| Globex Brand Refresh | 5 | Globex |

Acme tasks worth knowing: **Investigate failed Stripe webhook** (high priority, in progress, assigned to Alice, overdue, three comments), **Redesign pricing page** (high, in progress, Alice, two comments), **Prepare launch checklist** (unassigned), **Improve subscription cancellation flow** (done, Bob).

## Connecting Claude Code (lessons 01–06)

Create a token as the demo user in **Settings → MCP access**, then add one local server per user and workspace:

```bash
read -rs 'TEAMBOARD_TOKEN?Paste your MCP token: '; echo
claude mcp add --transport http --scope local teamboard-alice \
  https://teamboard.test/mcp/workspaces/acme \
  --header "Authorization: Bearer $TEAMBOARD_TOKEN"
unset TEAMBOARD_TOKEN
export NODE_EXTRA_CA_CERTS="$HOME/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem"
claude
```

Inside Claude Code, `/mcp` shows each server's status and tools. After switching tags, restart Claude Code (or reconnect in `/mcp`) so it refetches the tool list. When you finish a lesson, revoke the tokens and remove the servers:

```bash
claude mcp remove teamboard-alice -s local
```

Never show a full token on screen; the token box in Settings and the `read -rs` prompt keep it out of shell history.
