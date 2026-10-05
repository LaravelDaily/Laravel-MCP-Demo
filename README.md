# TeamBoard

TeamBoard is a small multi-tenant Laravel SaaS with workspaces, projects, tasks, and comments. It is the application used to demonstrate making an existing Laravel SaaS agent-ready through the official Laravel MCP package: an authenticated, workspace-scoped MCP server with twelve tools, a resource template, a prompt, per-user rate limiting, and an owner-visible audit trail.

## Requirements

- PHP 8.4 with the [Laravel Herd](https://herd.laravel.com/) default extensions (or an equivalent local PHP setup with SQLite)
- Composer 2
- Node.js 22 and npm (used for the frontend build and for Node-based MCP clients such as Inspector)
- An HTTPS local URL for MCP clients; with Herd, `herd secure teamboard`

The application uses Laravel 13, Livewire 4, Flux 2 (free edition), Laravel MCP 1.0, Laravel Sanctum 4, Pest 5, Larastan, and Pint. Exact versions are locked in `composer.lock` and `package-lock.json`.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
```

If `database/database.sqlite` does not exist, create it first (`touch database/database.sqlite`) or accept `php artisan migrate`'s offer to create it. Seeding creates the demo workspaces and accounts below. For an existing local database, run `php artisan migrate`, then `php artisan db:seed` if demo data is missing. The demo seeder warns and does nothing when demo workspaces already exist; do not reset a database containing data you need.

### Environment

The defaults in `.env.example` work for the local demo. The settings that matter are:

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_URL` | `https://teamboard.test` | Base URL used in links and the MCP endpoint examples |
| `DB_CONNECTION` | `sqlite` | The database defaults to `database/database.sqlite` |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` | Stored in the same database; no Redis or other service is required |
| `TEAMBOARD_MCP_RATE_LIMIT` | `60` | MCP requests per minute per user (see [Rate limiting](#rate-limiting)) |

The application timezone is fixed to UTC in `config/app.php`, and times on TeamBoard pages are shown in UTC. If you cache configuration with `php artisan config:cache`, run `php artisan config:clear` after editing `.env`.

### Running the app

The local demo uses `https://teamboard.test`. Run `herd secure teamboard` yourself to enable trusted HTTPS; Herd then serves the application directly, and `npm run build` is enough for the frontend. Students without Herd should substitute their own HTTPS URL and update `APP_URL`.

To run the starter kit's development processes instead (for example while changing frontend assets), run:

```bash
composer run dev
```

Log in at `https://teamboard.test/login` with a demo account.

## Demo accounts

All demo data is deterministic and intended **for local use only**. Every demo account uses the password `password`; never reuse these credentials anywhere else.

| Account | Email | Memberships |
| --- | --- | --- |
| Alice | `alice@example.com` | Acme **owner** |
| Bob | `bob@example.com` | Acme **member** |
| Charlie | `charlie@example.com` | Acme **viewer** |
| Dana | `dana@example.com` | Acme **owner**, Sandbox **viewer** |
| Eve | `eve@example.com` | Globex **owner** |

The Acme workspace contains the *Website Redesign* and *Billing Improvements* projects with a mix of task statuses, priorities, assignees, due dates (relative to the seeding date), and comments. Dana demonstrates workspace switching and holding different roles in different workspaces. Nobody from Acme belongs to Globex, so cross-tenant mistakes are easy to spot.

## Roles

| Ability in the selected workspace | Owner | Member | Viewer |
| --- | --- | --- | --- |
| View projects, tasks, comments, members | Yes | Yes | Yes |
| Create, edit, delete projects | Yes | No | No |
| Create and edit tasks, assign, change status, comment | Yes | Yes | No |
| Delete tasks | Yes | No | No |
| View the MCP audit log | Yes | No | No |

Any member, including a viewer, may be assigned a task. Comment authors always come from the logged-in or token-authenticated user.

## Identity, membership, and policies

TeamBoard keeps three questions separate:

1. **Identity** — who is calling? The browser uses the starter kit's session login; the MCP endpoint uses a Sanctum bearer token. Neither carries a role.
2. **Membership** — may this user use this workspace? The selected workspace (browser switcher or MCP URL) must be one of the user's memberships. Foreign and missing workspaces or records are reported as not found (404 on the web), so responses never reveal that they exist.
3. **Policies** — what may this user do here? The workspace, project, task, and comment policies check the user's role in the selected workspace. A role denial inside an accessible workspace is 403 on the web and a specific error message over MCP.

The web UI and MCP tools share the same task actions, validation, and policies, so the rules are the same whichever way a change arrives.

## Using the app

The domain hierarchy is workspace → project → task → comment. Log in as Alice (`alice@example.com`) to explore:

- The **workspace switcher** in the sidebar lists only your workspaces (with your role in each) and stores the selection per browser session. Switching always returns to the dashboard, so a project or task page cannot keep showing the previous workspace's records. Log in as Dana to switch between Acme (owner) and Sandbox (viewer).
- The **dashboard** summarizes the selected workspace: project count, open tasks (`todo` + `in_progress`), and your own unfinished assignments. Users without any workspace membership see an empty state ("No workspace yet") instead of someone else's data; TeamBoard never creates a membership for them.
- **Projects** lists the selected workspace's projects. Only owners can create, edit, and delete projects (deleting a project removes its tasks and comments). The project page shows the description, the AI instructions, and the project's tasks. AI instructions are guidance for MCP clients, not validation rules that TeamBoard enforces.
- A **task** page shows its metadata, comments, and — for owners and members — inline controls to change status and assignment, an edit form for title/description/priority/due date, and a comment box. Deleting a task (owners only) also deletes its comments.
- **MCP audit log** (owners only) lists changes made through MCP tools; see [MCP audit log](#mcp-audit-log).

Every page and mutation is scoped to the selected workspace: foreign records return 404, and role denials within an accessible workspace return 403.

## Testing and static analysis

```bash
php artisan test --compact
composer types:check
vendor/bin/pint --dirty --format agent
npm run build
```

`composer types:check` runs Larastan (PHPStan level 7). The tests use an in-memory SQLite database and the array cache, so they never touch your local data, and they include real bearer-token HTTP JSON-RPC requests against the MCP endpoint. They do not replace a check with a real MCP client.

## MCP connection

### Tokens

Log in and open **Settings → MCP access** (`/settings/mcp-access`). Create a named personal access token (name up to 100 characters) and copy it immediately: plaintext is shown only on creation. The list shows creation and last-used times in UTC. Tokens do not expire; **revocation is the control** — revoke a token from this page when it is no longer needed, and requests with it receive 401 from then on. Stored hashes and other users' tokens are never displayed. Tokens have no owner/member/viewer abilities: what a token can do follows its user's role in each workspace. Never commit tokens to Git or paste them into shared files.

### Workspace endpoints

The MCP endpoint is `https://teamboard.test/mcp/workspaces/{slug}`; demo slugs are `acme`, `sandbox`, and `globex`. Each workspace is a separate MCP server URL, and one token can connect to every workspace its user belongs to. Send `Authorization: Bearer <token>` on every request. Browser sessions are not accepted for MCP authentication. The URL selects the workspace independently of the browser switcher. Missing, invalid, or revoked tokens receive 401; unknown or inaccessible workspace slugs receive 404 without workspace details. Only HTTP POST is supported (GET and DELETE return 405).

### Rate limiting

MCP requests are rate limited per authenticated user: by default 60 JSON-RPC requests per minute, shared across every workspace URL and every token of that user, and applied to all methods including `initialize` and `tools/list`. Authentication runs first, so requests with missing or invalid tokens receive 401 and do not use anyone's budget. When the budget is exhausted the endpoint returns HTTP 429 with `Retry-After`, `X-RateLimit-Limit`, and `X-RateLimit-Remaining` headers, and the rejected request is not executed. Set `TEAMBOARD_MCP_RATE_LIMIT` in `.env` (config key `teamboard.mcp_rate_limit_per_minute`) to change the limit, for example `TEAMBOARD_MCP_RATE_LIMIT=5` to demonstrate a 429; run `php artisan config:clear` afterwards if configuration is cached, and restore the value when done. Counters use the default cache store (`CACHE_STORE=database` locally), so no Redis or other distributed cache is required.

### Read tools

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

**Pagination and bounded outputs.** All lists are ordered by ascending ID. `page` defaults to 1, `per_page` defaults to 25 and accepts 1–100. The structured response contains `data` and `meta: { page, per_page, total, last_page }`; pages past the end return empty data, and totals count only the endpoint workspace and the applied filters. Each task summary has only `id`, `title`, `project: { id, name }`, `status` (`todo`, `in_progress`, `done`), `priority` (`low`, `medium`, `high`), nullable `assignee: { id, name }`, and nullable `due_date` (`YYYY-MM-DD`). `get_task` returns at most the latest 20 comments as `id`, `body`, and `author: { id, name }`, ordered by creation time descending and then ID descending, plus `comments_total`. No tool returns timestamps, emails, hidden attributes, or unbounded histories. Every tool supplies a matching JSON text fallback alongside structured content. Reads do not change domain records.

### Write tools

| Tool | Inputs | Result |
| --- | --- | --- |
| `create_task` | Required `project_id`, `title`; optional nullable `description`, `assignee_id`, `due_date`; optional `priority` | Task summary plus description; defaults to `todo` and `medium` |
| `update_task` | Required `task_id`; at least one of `title`, nullable `description`, `priority`, nullable `due_date` | Task summary plus description; omitted fields remain unchanged |
| `assign_task` | Required `task_id`, required nullable `assignee_id` | Task summary; any workspace member (including viewers) may be assigned, null unassigns |
| `move_task` | Required `task_id`, `status` (`todo`, `in_progress`, `done`) | Task summary; any transition is allowed, including reopening completed tasks |
| `add_comment` | Required `task_id`, `body` | `id`, `task_id`, trimmed `body`, `author: { id, name }` from authenticated identity |
| `delete_task` | Required `task_id` | `deleted: true` and `task: { id, title }` captured before deletion; permanently deletes the task and its comments |

Owners and members may create, update, assign, move, and comment; only owners may delete tasks. Supplied titles are trimmed and must be non-empty with at most 255 characters; descriptions accept up to 10,000 characters. Due dates use strict `YYYY-MM-DD`, including past dates. Creation may assign any member of the endpoint workspace, including a viewer, or leave the task unassigned. Explicit null clears description and due date on update; title and priority cannot be null. Update rejects status, assignee, and project changes. All six tools reject unsupported arguments and return safe workspace errors. Comment bodies are trimmed, required, and limited to 5,000 characters. Assignment requires an explicit assignee ID or null; omitting it is an error. Policy denials identify the operation your workspace role cannot perform. Creation and comments can duplicate on retry; no write tool promises idempotency or exactly-once execution.

**Destructive operations.** `delete_task` is the only destructive tool. It is annotated `destructiveHint: true` and not idempotent: deletion cannot be undone, it also deletes the task's comments, and repeating it reports that the task was not found. It is offered only to owners. Safety annotations are advisory: they do not guarantee that a client shows a confirmation dialog, so confirm deletions yourself before approving the call. The audit log keeps the deleted task's ID and title.

### Role-based tool discovery

Tool discovery follows your role in the URL-selected workspace, checked on every request:

| Role | Tools listed |
| --- | --- |
| Owner | All twelve: six read tools, `create_task`, `update_task`, `assign_task`, `move_task`, `add_comment`, `delete_task` |
| Member | Eleven: six read tools and the five writes above, without `delete_task` |
| Viewer | Six read tools only |

The same token can see different tool sets on different workspace URLs: Dana sees all twelve tools on `/acme` (owner) and only the six read tools on `/sandbox` (viewer). Hidden tools are a usability filter, not the security boundary: calling a hidden tool by name is rejected as an unknown tool, and every write still runs through the shared policies and task actions, so a guessed name cannot change data. Reconnect your client after a role change to refresh its tool list.

### Project instructions resource and review prompt

TeamBoard also exposes one resource template and one prompt. Both are available to owners, members, and viewers, operate only in the URL-selected workspace, and never change data.

**Resource template `project://{project}/instructions`** (name `project_instructions`, MIME type `application/json`). Replace `{project}` with a project ID from `list_projects`, for example `project://1/instructions` for Acme's Website Redesign. The content is JSON with exactly `project: { id, name, description }` and nullable `instructions`; a project without guidance returns `"instructions": null`. It is listed by `resources/templates/list`, not as one static resource per project, so `resources/list` is empty. Foreign, missing, and malformed project IDs all return the same JSON-RPC error (`-32602`, "The project was not found in the current workspace.") without project details; a URI that does not match the template is reported as an unknown resource.

**Prompt `project_review`** with required argument `project`: a project name or ID in the endpoint workspace, because people type prompt arguments by hand. An ID or a case-insensitive exact name wins; otherwise part of a name works when it matches exactly one project (`website` finds "Website Redesign"). The server returns one user message that names the project and workspace, today's date in the application timezone (UTC), the resource URI to read, and the read tools to use (`list_tasks` with pagination, `get_task`, and optionally `search_tasks` and `list_workspace_members`). It asks the client to report overdue unfinished tasks (due date strictly before today), high-priority and unassigned unfinished tasks, and likely blockers inferred from descriptions and comments, then summarize and recommend the next three actions. It tells the client not to change data unless the user makes a separate request. The server only generates the text: it does not call a model, run tools, or guarantee that a host reads the resource. A missing argument, a name matching several projects, and a project that isn't in the workspace each get a validation error; foreign and missing projects get the same "No project in the current workspace matches …" error, so other workspaces are never revealed.

### MCP audit log

Every successful MCP `create_task`, `update_task`, `assign_task`, `move_task`, `add_comment`, and `delete_task` call writes one row to `mcp_audit_logs` in the same database transaction as the change: if the audit insert fails, the change is rolled back. A row stores the actor, the URL-selected workspace, the exact tool name, an entity reference (`comment` for `add_comment`, `task` otherwise), and a short summary such as `Moved task #12 "Fix mobile navigation" to in_progress.` It never stores tokens, request payloads, descriptions, or comment bodies. Entity references are plain IDs, so history remains after a task and its comments are deleted. Failed calls, rate-limited requests, reads, resources, prompts, and changes made in the web UI are not recorded. Repeating a successful call records another entry.

Workspace owners see **MCP audit log** in the sidebar (`https://teamboard.test/mcp-audit`): paginated entries for the selected workspace, newest first, with time (UTC), tool, actor, entity, and summary. Members and viewers do not see the link and receive 403 if they open the URL.

## Connecting a client

### Claude Code

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

The `NODE_EXTRA_CA_CERTS` path is Herd's default CA location on macOS; adjust it for your environment so Node-based clients trust the local certificate. Keep TLS verification enabled. The local scope keeps credentials out of a project `.mcp.json`; never commit tokens or client secrets.

Run `/mcp` inside Claude Code and check the connection. Try the read tools without changing data:

- “Use teamboard-acme list_projects to list projects and their open task counts.”
- “Use teamboard-acme list_tasks with page 1 and per_page 2. Show pagination metadata, then fetch page 2.”
- “Use teamboard-acme list_my_tasks to list my assignments, including completed tasks.”
- “Use teamboard-acme search_tasks with query PRICING. Then filter to the Website Redesign project ID from list_projects.”
- “Use teamboard-acme get_task with an ID returned by list_tasks. Show description, comments, and comments_total.”
- “Use teamboard-acme list_workspace_members to find Bob by name and show his ID and role.”
- “Call list_tasks with actor_id 999 and workspace_id 999 together and show both validation errors.”

Expect only Acme data.

**Write workflow.** As Alice, ask Claude Code to use `list_projects` to find Website Redesign, then `create_task` with the title "MCP demo task". Keep the returned task ID and work only on that task. Use `update_task` to set its description and priority; use `list_workspace_members` to find Bob, then `assign_task` with his ID; use `move_task` to set `in_progress`; and use `add_comment` with the body "MCP workflow checked". Use `get_task` to verify metadata, assignment, status, and comment author. Finally use `delete_task` on that test task only, and confirm with `get_task` that it is no longer found. Open **MCP audit log** as Alice in the browser to see one entry per write, newest first, including the deletion.

**Roles and tenants.** Connect a separate local server for each demo user with that user's own token (for example `teamboard-acme-bob`, `teamboard-acme-charlie`, and `teamboard-sandbox-dana` for `https://teamboard.test/mcp/workspaces/sandbox`). Run `/mcp` to inspect each server's tools: Bob has no `delete_task`, Charlie and Dana's Sandbox connection have only read tools. Asking Bob's connection to call `delete_task` on a task ID fails as an unknown tool and the task remains. To test tenant denial, add another local server named `teamboard-globex` with Alice's token and the URL ending `/globex`; it must fail with 404.

**Resource and prompt.**

- Resource: type `@teamboard-acme:project://1/instructions` in a message (for example "Summarize @teamboard-acme:project://1/instructions"). Because this is a template, it may not appear in the `@` autocomplete list; type the full URI. You can also ask Claude to "read the MCP resource project://1/instructions from teamboard-acme".
- Prompt: type `/` and pick `/teamboard-acme:project_review (MCP)`, or run `/mcp__teamboard-acme__project_review website` (a project name, part of one, or an ID such as `1`). Claude then performs the review with the read tools; approve the tool calls as usual.
- Try `project://4/instructions` or `/mcp__teamboard-acme__project_review payroll` (a Globex project) to see the not-found error without Globex details.

**Revocation.** Revoke the token in Settings → MCP access, reconnect the Acme server in `/mcp`, and confirm authentication fails.

### Inspector

Read `TEAMBOARD_TOKEN` again with the hidden prompt above before running [Inspector CLI](https://github.com/modelcontextprotocol/inspector/blob/main/clients/cli/README.md) with the same endpoint and header. Use an unrevoked token:

```bash
npx -y @modelcontextprotocol/inspector --cli \
  https://teamboard.test/mcp/workspaces/acme --transport http \
  --method tools/list --header "Authorization: Bearer $TEAMBOARD_TOKEN"
npx -y @modelcontextprotocol/inspector --cli \
  https://teamboard.test/mcp/workspaces/acme --transport http \
  --method tools/call --tool-name list_tasks --tool-arg per_page=2 \
  --header "Authorization: Bearer $TEAMBOARD_TOKEN"
npx -y @modelcontextprotocol/inspector --cli \
  https://teamboard.test/mcp/workspaces/acme --transport http \
  --method resources/templates/list --header "Authorization: Bearer $TEAMBOARD_TOKEN"
npx -y @modelcontextprotocol/inspector --cli \
  https://teamboard.test/mcp/workspaces/acme --transport http \
  --method resources/read --uri project://1/instructions \
  --header "Authorization: Bearer $TEAMBOARD_TOKEN"
npx -y @modelcontextprotocol/inspector --cli \
  https://teamboard.test/mcp/workspaces/acme --transport http \
  --method prompts/list --header "Authorization: Bearer $TEAMBOARD_TOKEN"
npx -y @modelcontextprotocol/inspector --cli \
  https://teamboard.test/mcp/workspaces/acme --transport http \
  --method prompts/get --prompt-name project_review --prompt-args project=website \
  --header "Authorization: Bearer $TEAMBOARD_TOKEN"
unset TEAMBOARD_TOKEN
```

Inspector needs the same `NODE_EXTRA_CA_CERTS` setting as Claude Code. To see the rate limit, set `TEAMBOARD_MCP_RATE_LIMIT=5` and repeat the `tools/list` command within a minute. Each Inspector CLI run sends several JSON-RPC requests (initialization plus the method), so a run soon fails with HTTP 429. Restore the value afterwards.
