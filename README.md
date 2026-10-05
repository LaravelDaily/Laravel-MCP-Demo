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

Log in and open **Settings → MCP access** (`/settings/mcp-access`). Create a named personal access token and copy it immediately: plaintext is shown only on creation. The list shows creation and last-used times in UTC. Tokens do not expire; revoke them from this page when no longer needed. Stored hashes and other users' tokens are never displayed.

The MCP endpoint is `https://teamboard.test/mcp/workspaces/{slug}`; demo slugs are `acme`, `sandbox`, and `globex`. Send `Authorization: Bearer <token>` on every request. Browser sessions are not accepted for MCP authentication. Identity comes from Sanctum, membership controls workspace access, and existing policies control operations; token abilities do not represent roles. The URL selects the workspace independently of the browser switcher. Invalid tokens receive 401; unknown or inaccessible workspace slugs receive 404.

The read surface is available to owners, members, and viewers. Each tool operates only in the URL-selected workspace:

| Tool | Inputs beyond pagination | Result |
| --- | --- | --- |
| `list_tasks` | Optional `project_id`, `status`, `priority`, `assignee_id`, `search` | Filtered task summaries |
| `list_projects` | Optional `search` in name/description | `id`, `name`, nullable `description`, `open_task_count` (todo plus in_progress) |
| `list_my_tasks` | Optional `status`, `priority` | Tasks assigned to the authenticated user; includes completed tasks by default |
| `get_task` | Required `task_id`; no pagination | Task summary, nullable `description`, latest 20 comments, `comments_total` |
| `search_tasks` | Required `query`; optional `status`, `project_id` | Task summaries matching title or description |
| `list_workspace_members` | Optional `search` in name only | `id`, `name`, workspace-specific `role`; no email or private fields |

Search strings are trimmed literal substrings of up to 200 characters. Search is case-insensitive for ASCII letters on SQLite; non-ASCII case folding depends on the database and its configuration. `%`, `_`, and `!` are treated literally. Comparisons apply the database’s `LOWER` function to both the column and bound pattern. Filters combine conjunctively and project/member IDs must belong to the endpoint workspace. Unsupported arguments are reported together; explicit null filters are rejected. No read tool accepts a caller-supplied actor or workspace.

All lists are ordered by ascending ID. `page` defaults to 1, `per_page` defaults to 25 and accepts 1–100. The structured response contains `data` and `meta: { page, per_page, total, last_page }`; pages past the end return empty data. Each task summary has only `id`, `title`, `project: { id, name }`, `status` (`todo`, `in_progress`, `done`), `priority` (`low`, `medium`, `high`), nullable `assignee: { id, name }`, and nullable `due_date` (`YYYY-MM-DD`). `get_task` returns comments as `id`, `body`, and `author: { id, name }`, ordered by creation time descending and then ID descending. Every tool supplies a matching JSON text fallback alongside structured content. Reads do not change domain records.

Two metadata write tools are also available:

| Tool | Inputs | Result |
| --- | --- | --- |
| `create_task` | Required `project_id`, `title`; optional nullable `description`, `assignee_id`, `due_date`; optional `priority` | Task summary plus description; defaults to `todo` and `medium` |
| `update_task` | Required `task_id`; at least one of `title`, nullable `description`, `priority`, nullable `due_date` | Task summary plus description; omitted fields remain unchanged |

Owners and members may write; viewer calls are denied by the shared policies and task actions. Discovery includes these tools for all workspace roles until role-specific registration is introduced in the permissions lesson. Supplied titles are trimmed and must be non-empty with at most 255 characters; descriptions accept up to 10,000 characters. Due dates use strict `YYYY-MM-DD`, including past dates. Creation may assign any member of the endpoint workspace, including a viewer, or leave the task unassigned. Explicit null clears description and due date on update; title and priority cannot be null. Update rejects status, assignee, and project changes. Both tools reject unsupported arguments and return safe workspace errors. Creation can duplicate on retry; neither tool promises idempotency or exactly-once execution. Safety annotations are advisory and do not guarantee a client confirmation dialog.

To try metadata writes deliberately, ask Claude Code to create a test task in a project ID from `list_projects`, then update only its description or priority. Use `get_task` to verify the result and confirm omitted fields stayed unchanged. Delete the test task through the normal owner UI when finished.

[Claude Code HTTP setup](https://code.claude.com/docs/en/mcp): from a terminal in this project, read the token without adding it to shell history (zsh):

```bash
read -rs 'TEAMBOARD_TOKEN?Paste your MCP token: '; echo
claude mcp add --transport http --scope local teamboard-acme \
  https://teamboard.test/mcp/workspaces/acme \
  --header "Authorization: Bearer $TEAMBOARD_TOKEN"
unset TEAMBOARD_TOKEN
export NODE_EXTRA_CA_CERTS="$HOME/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem"
claude
```

Run `/mcp` inside Claude Code and check the connection. Try all six tools without changing data:

- “Use teamboard-acme list_projects to list projects and their open task counts.”
- “Use teamboard-acme list_tasks with page 1 and per_page 2. Show pagination metadata, then fetch page 2.”
- “Use teamboard-acme list_my_tasks to list my assignments, including completed tasks.”
- “Use teamboard-acme search_tasks with query PRICING. Then filter to the Website Redesign project ID from list_projects.”
- “Use teamboard-acme get_task with an ID returned by list_tasks. Show description, comments, and comments_total.”
- “Use teamboard-acme list_workspace_members to find Bob by name and show his ID and role.”
- “Call list_tasks with actor_id 999 and workspace_id 999 together and show both validation errors.”

Expect only Acme data. Before revoking the token, test tenant denial by adding another local server named `teamboard-globex` with the same Alice token and URL ending `/globex`; it must fail with 404. Then revoke the token in Settings → MCP access, reconnect the Acme server in `/mcp`, and confirm authentication fails. The local scope keeps credentials out of a project `.mcp.json`; never commit tokens or client secrets.

Read `TEAMBOARD_TOKEN` again with the hidden prompt above before running [Inspector CLI](https://github.com/modelcontextprotocol/inspector/blob/main/clients/cli/README.md) with the same endpoint and header. Use an unrevoked token:

```bash
npx -y @modelcontextprotocol/inspector --cli \
  https://teamboard.test/mcp/workspaces/acme --transport http \
  --method tools/list --header "Authorization: Bearer $TEAMBOARD_TOKEN"
npx -y @modelcontextprotocol/inspector --cli \
  https://teamboard.test/mcp/workspaces/acme --transport http \
  --method tools/call --tool-name list_tasks --tool-arg per_page=2 \
  --header "Authorization: Bearer $TEAMBOARD_TOKEN"
unset TEAMBOARD_TOKEN
```

The `NODE_EXTRA_CA_CERTS` example above is the macOS Herd CA path verified here; adjust it for your environment before running Node clients. Keep TLS verification enabled. Automated transport tests do not substitute for that client check.
