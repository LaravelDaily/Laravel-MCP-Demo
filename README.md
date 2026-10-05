# TeamBoard

TeamBoard is a small multi-tenant Laravel SaaS with workspaces, projects, tasks, and comments. It is the application used to demonstrate making an existing Laravel SaaS agent-ready through the official Laravel MCP package.

## Requirements

- PHP 8.4 with the [Laravel Herd](https://herd.laravel.com/) default extensions (or an equivalent local PHP setup)
- Composer
- Node.js and npm

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
```

The local demo uses `https://teamboard.test`. Set `APP_URL=https://teamboard.test` in `.env` and run `herd secure teamboard` yourself to enable trusted HTTPS. Students without Herd should substitute their own HTTPS URL. Then serve the application with Herd, or run:

```bash
composer run dev
```

The database defaults to `database/database.sqlite`. Seeding creates the demo workspaces and accounts below. For an existing local database, run `php artisan migrate`, then `php artisan db:seed` if demo data is missing. The demo seeder warns and does nothing when demo workspaces already exist; do not reset a database containing data you need.

## Demo accounts

All demo data is deterministic and intended for local use only. Every demo account uses the password `password`.

| Account | Email | Memberships |
| --- | --- | --- |
| Alice | `alice@example.com` | Acme **owner** |
| Bob | `bob@example.com` | Acme **member** |
| Charlie | `charlie@example.com` | Acme **viewer** |
| Dana | `dana@example.com` | Acme **owner**, Sandbox **viewer** |
| Eve | `eve@example.com` | Globex **owner** |

The Acme workspace contains the *Website Redesign* and *Billing Improvements* projects with a mix of task statuses, priorities, assignees, due dates, and comments. Dana demonstrates workspace switching and holding different roles in different workspaces. Nobody from Acme belongs to Globex, so cross-tenant mistakes are easy to spot.

## Roles

- **Owner** — manages projects and tasks, comments, and deletes tasks
- **Member** — creates and edits tasks, assigns work, changes status, comments
- **Viewer** — read-only access, but may be assigned tasks

## Using the app

The domain hierarchy is workspace → project → task → comment. Log in as Alice (`alice@example.com`) to explore:

- The **workspace switcher** in the sidebar lists only your workspaces (with your role in each) and stores the selection per browser session. Switching always returns to the dashboard, so a project or task page cannot keep showing the previous workspace's records.
- The **dashboard** summarizes the selected workspace: project count, open tasks (`todo` + `in_progress`), and your own unfinished assignments. Users without any workspace membership see an empty state instead of someone else's data.
- **Projects** lists the selected workspace's projects. Only owners can create, edit, and delete projects (deleting a project removes its tasks and comments). The project page shows the description, the AI instructions, and the project's tasks.
- A **task** page shows its metadata, comments, and — for owners and members — inline controls to change status and assignment, an edit form for title/description/priority/due date, and a comment box. Deleting a task (owners only) also deletes its comments.

Every page and mutation is scoped to the selected workspace: foreign records return 404, and role denials within an accessible workspace return 403.

## Testing

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
composer types:check
```

## MCP connection

Log in and open **Settings → MCP access** (`/settings/mcp-access`). Create a named personal access token and copy it immediately: plaintext is shown only on creation. Tokens do not expire; revoke them from this page when no longer needed. Stored hashes and other users' tokens are never displayed.

The MCP endpoint is `https://teamboard.test/mcp/workspaces/{slug}`; demo slugs are `acme`, `sandbox`, and `globex`. Send `Authorization: Bearer <token>` on every request. Browser sessions are not accepted for MCP authentication. Identity comes from Sanctum, membership controls workspace access, and existing policies control operations; token abilities do not represent roles. The URL selects the workspace independently of the browser switcher. Invalid tokens receive 401; unknown or inaccessible workspace slugs receive 404.

This checkpoint supports initialization; tools are introduced in the next commit.

[Claude Code HTTP setup](https://code.claude.com/docs/en/mcp): from a terminal in this project, read the token without adding it to shell history (zsh):

```bash
read -rs 'TEAMBOARD_TOKEN?Paste your MCP token: '; echo
claude mcp add --transport http --scope local teamboard-acme \
  https://teamboard.test/mcp/workspaces/acme \
  --header "Authorization: Bearer $TEAMBOARD_TOKEN"
unset TEAMBOARD_TOKEN
claude
```

Run `/mcp` inside Claude Code and check the connection. The local scope keeps credentials out of a project `.mcp.json`; never commit tokens or client secrets. Revoke your token after testing.

For [Inspector CLI](https://github.com/modelcontextprotocol/inspector/blob/main/clients/cli/README.md), use the same endpoint and header:

```bash
npx -y @modelcontextprotocol/inspector --cli \
  https://teamboard.test/mcp/workspaces/acme --transport http \
  --method tools/list --header "Authorization: Bearer $TEAMBOARD_TOKEN"
```

For Node clients that do not trust Herd's local CA, export `NODE_EXTRA_CA_CERTS` with the path to Herd's CA certificate before starting the client. Keep TLS verification enabled. Automated transport tests do not substitute for a real client connection; manual verification is reported at each course checkpoint.
