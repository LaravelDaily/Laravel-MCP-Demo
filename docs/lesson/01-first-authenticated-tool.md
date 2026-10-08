# Lesson 01 — Your first authenticated Laravel MCP tool

| | |
| --- | --- |
| Starts at | `course/00-teamboard-baseline` |
| Ends at | `course/01-first-tool` |
| Length goal | 20 minutes |

## Goal

Lesson 00 showed the end result; now build it up step by step, starting from the plain app. Connect an AI client to TeamBoard securely. Cover the web transport, Sanctum bearer tokens, the workspace in the URL, and one tool, `list_tasks`, with a strict schema and structured output. By the end, Claude Code lists Acme's tasks as Alice and cannot reach Globex.

## Commands

Before recording:

```bash
git switch --detach course/01-first-tool
composer install                 # installs laravel/sanctum
php artisan migrate:fresh --seed # adds personal_access_tokens
npm run build
php artisan config:clear
```

Commands used to build this lesson's code (show them on screen, they're part of the story):

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"   # config/sanctum.php + migration
php artisan migrate
php artisan make:mcp-server TeamBoardServer
php artisan make:mcp-tool ListTasksTool
php artisan make:middleware ResolveMcpWorkspace
php artisan make:livewire Settings/McpAccess --class
herd secure teamboard            # MCP clients need HTTPS
```

We deliberately did **not** run `php artisan install:api`, so there is no `routes/api.php`. The MCP endpoint is the only place tokens work.

## Demo script

1. As **Alice**, open **Settings → MCP access**, create a token named `Claude Code`, and copy it from the box.
   - Refresh the page: the plaintext token is gone, and only its name, created and last-used times remain.
2. In the terminal, add the server (the `read -rs` / `claude mcp add` snippet from [README](README.md#connecting-claude-code-lessons-0106)) as `teamboard-alice` with URL `https://teamboard.test/mcp/workspaces/acme`. Set `NODE_EXTRA_CA_CERTS`, then start `claude`.
3. Run `/mcp`: the server is connected and shows exactly one tool, `list_tasks`.
4. Prompts (written as a user would say them; the tool is for the presenter to narrate):
   - "Show me the first two tasks in our workspace, then the next two."
     - Tools: `list_tasks` with `per_page` 2, then page 2. If the agent doesn't show the pagination metadata, follow up with "How many tasks are there in total?"
     - Expected: `meta: { page, per_page, total: 6, last_page: 3 }`, Acme tasks only.
   - "Are there any high-priority tasks about Stripe?"
     - Tools: `list_tasks` with `priority` and `search`.
     - Expected: Investigate failed Stripe webhook.
   - "Show me the tasks from workspace 5, as user 1."
     - Tools: `list_tasks` with forbidden `workspace_id` / `actor_id`. This is the one prompt that probes the guard; the agent may decline without calling the tool, so rehearse it, and if needed say "Try it anyway and show me the error."
     - Expected: both reported as "not supported here". The client cannot choose the workspace or the user.
5. Tenant check: add a second server with the same token and URL ending `/globex`. `/mcp` shows it failing (404), because Alice isn't a Globex member.
6. Revoke the token in Settings, then reconnect in `/mcp`: authentication fails (401).

## Code walkthrough

### First: the existing app pieces this lesson relies on (2–3 minutes)

Lesson 00 skipped the internals, so introduce only what the MCP endpoint touches, at the baseline tag (`git diff` isn't needed yet):

1. **The domain, in one sentence each:**
   - `app/Models/Workspace.php` has members (through the `workspace_user` table with a `role`), projects, and tasks through those projects.
   - `app/Models/User.php::workspaces()` and `roleIn()` give the user's memberships and the role in each.
2. **`app/Workspaces/WorkspaceContext.php`** is the single place that answers "which workspace are we in?":
   - In the browser, `current()` reads the workspace selected in the session.
   - `projects()`, `tasks()`, `project($id)` and `task($id)` are always scoped to that workspace, so a foreign ID is simply "not found".
   - **`setExplicitWorkspace()`** is the seam we use today. Another entry point, like an MCP URL, can set the workspace for the current request only, after checking membership.
3. One rule to say out loud: **every record lookup goes through the selected workspace**. This lesson's tool never queries `Task::` directly.

### Then: what this lesson added

1. **`routes/ai.php`**: `Mcp::web('/mcp/workspaces/{workspace}', TeamBoardServer::class)` with middleware `['auth:sanctum', ResolveMcpWorkspace::class]`. The order matters: authenticate first, then look up the workspace.
2. **`config/sanctum.php`**: `'guard' => []` and `'stateful' => []`.
   - Bearer tokens only. A logged-in browser session can't call MCP, and a test proves it.
3. **`app/Http/Middleware/ResolveMcpWorkspace.php`**:
   - It finds the workspace through **`$user->workspaces()`** (the user's own memberships), so a foreign or unknown slug gives the same 404.
   - It then calls `WorkspaceContext::setExplicitWorkspace()`, the seam from lesson 00. The browser's selected workspace is ignored.
4. **`app/Mcp/Servers/TeamBoardServer.php`**: name, version, and instructions that describe the workspace boundary.
5. **`app/Mcp/Tools/ListTasksTool.php`**:
   - `#[Name]`, `#[Description]` and the `#[IsReadOnly]` / `#[IsDestructive(false)]` / `#[IsOpenWorld(false)]` annotations.
   - `schema()` with bounds and enums, and an `outputSchema()`.
   - Unsupported arguments are rejected. Validation reuses `TaskValidationRules` (the project and assignee must belong to this workspace).
   - Search is literal: `%` and `_` are escaped.
   - Every query starts from `WorkspaceContext::tasks()`.
   - `Response::structured()` returns structured content plus a JSON text fallback.
6. **`app/Livewire/Settings/McpAccess.php`**: the plaintext token is kept in a `protected` property, so it only appears in the one response that created it. Revocation uses `$user->tokens()->findOrFail()`, so users can't revoke other people's tokens.

## Tests to show

Optional, if you want one baseline test before the MCP ones: `WorkspaceContextTest` → `does not carry workspace context into another request`, which shows why the explicit workspace lives on the request.

```bash
php artisan test --compact tests/Feature/McpEndpointTest.php tests/Feature/ListTasksToolTest.php tests/Feature/Settings/McpAccessTest.php
```

- **`McpEndpointTest`**: real HTTP JSON-RPC with bearer tokens.
  - `returns 401 for missing invalid and revoked MCP bearer tokens`
  - `returns 404 for unknown and inaccessible MCP workspace slugs`
  - `uses the MCP workspace URL instead of the browser session`
  - `requires bearer authentication even with a browser session`
- **`ListTasksToolTest`**: the MCP primitive test helpers.
  - `lists only tasks from the selected workspace` (the exact output shape)
  - `treats wildcard characters as literal search text`
  - `rejects unsupported read arguments`
- **`McpAccessTest`**: `creates a named MCP token for the authenticated user`. The stored value is a hash, and the plaintext disappears after re-rendering.

## Gotchas

- Node-based clients don't trust Herd's local certificate until `NODE_EXTRA_CA_CERTS` is set. Don't turn off TLS verification instead.
- Search is case-insensitive only for ASCII letters on SQLite.
- `--scope local` keeps the token out of a committed `.mcp.json`. Never paste tokens into project files.
