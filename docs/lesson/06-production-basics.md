# Lesson 06 — Production basics: rate limits, audit trails, and verification

| | |
| --- | --- |
| Starts at | `course/05-resources-prompts` |
| Ends at | `course/06-production-basics` |
| Length goal | 15–20 minutes |

## Goal

Add the protections a real SaaS needs before letting agents in:
- a **per-user rate limit** on the MCP endpoint;
- a **transactional audit trail** of every agent write, with an owner-only page to review it;
- a final look at how the whole thing is verified.

## Commands

Before recording:

```bash
git switch --detach course/06-production-basics
composer install
php artisan migrate:fresh --seed   # adds the mcp_audit_logs table
npm run build                      # new audit page
php artisan config:clear
```

Commands used to build this lesson's code:

```bash
php artisan make:model McpAuditLog -mf
php artisan migrate
php artisan make:livewire McpAudit/Index --class
```

`config/teamboard.php` was created by hand. It holds one setting, `mcp_rate_limit_per_minute`, read from `TEAMBOARD_MCP_RATE_LIMIT` (default 60).

For the rate-limit demo, set this in `.env` and clear the config cache:

```bash
TEAMBOARD_MCP_RATE_LIMIT=5
php artisan config:clear
```

Remove it again after the demo (and run `php artisan config:clear` again).

## Demo script

1. Connect `teamboard-alice` (Alice, `/acme`).
2. Audit trail: run a short workflow on a new task:
   - "Use teamboard-alice to create_task 'Audit demo task' in project 1, assign it to Bob (find him with list_workspace_members), move it to in_progress, add_comment 'Looks good', then delete_task it."
3. In the browser as Alice, open **MCP audit log** in the sidebar. Expected:
   - five entries, newest first: `delete_task`, `add_comment`, `move_task`, `assign_task`, `create_task`;
   - each shows the actor, an entity reference and a short summary;
   - the deleted task's history is still there.
   - Point out what's **not** stored: no comment body, no description, no token.
4. Reads aren't audited: ask Claude to `list_tasks`, then refresh the audit page. Nothing new appears. Web UI changes aren't recorded either.
5. Owners only: log in as **Bob** and open `https://teamboard.test/mcp-audit` to get **403**. There's no sidebar link for him either.
6. Rate limit (with `TEAMBOARD_MCP_RATE_LIMIT=5`):
   - Restart Claude Code, then ask: "Use teamboard-alice list_tasks with per_page 1, then again for pages 2, 3, 4 and 5."
   - Expected: the calls start failing with HTTP 429 (Too Many Requests). `initialize` and discovery requests count too, so it may happen sooner than the fifth call.
   - The limit is per user, across all workspace URLs and tokens. Wait a minute, and it works again.
7. Show `README.md`: it's now the complete setup and connection guide.

## Code walkthrough

1. **Rate limit**:
   - **`routes/ai.php`**: middleware `['auth:sanctum', 'throttle:mcp', ResolveMcpWorkspace::class]`.
     - Authentication runs first, so invalid tokens get 401 and don't use anyone's budget.
     - The limit applies before the workspace lookup.
   - **`app/Providers/AppServiceProvider.php`**: `RateLimiter::for('mcp', ...)` with `Limit::perMinute(config('teamboard.mcp_rate_limit_per_minute'))->by('user:'.$user->id)`. It's keyed by the user, not the token or workspace.
2. **Audit trail**:
   - **Migration**: `user_id` is nullable with `nullOnDelete()`, so an actor deleting their account doesn't erase history. `entity_type` / `entity_id` are plain columns with no foreign key, so entries survive task deletion.
   - **`TeamBoardTool::recordAudit()`** writes the actor, the URL's workspace, the exact tool name, the entity reference and a short summary.
   - **Every write tool** wraps the shared action *and* the audit insert in one `DB::transaction()`, for example `DeleteTaskTool::execute()`. If the audit insert fails, the task change rolls back. The audit logic lives only in the MCP layer, so the web actions are untouched.
3. **Audit page**: `app/Livewire/McpAudit/Index.php`.
   - It shows the selected workspace only, ordered by `created_at` desc then `id` desc.
   - It authorizes `viewMcpAudit` (in `WorkspacePolicy`) on mount *and* on every render, which covers pagination too.
   - Deleted actors show as "Deleted user".

## Tests to show

```bash
php artisan test --compact tests/Feature/McpRateLimitTest.php tests/Feature/McpAuditLogTest.php tests/Feature/McpAuditPageTest.php tests/Feature/McpWorkflowTest.php
```

- **Rate limit:**
  - `returns 429 after the per-user MCP request limit is exhausted`: a throttled write changes nothing and isn't audited.
  - `shares a users rate limit across workspace connections`
  - `resumes MCP access after the rate limit window`: time travel instead of `sleep()`.
- **Audit entries:**
  - `logs exactly one audit entry for each successful MCP mutation`
  - `rolls back domain mutations when audit persistence fails`: fault injection at the audit insert only. Delete rolls back with its comments.
  - `keeps secrets and full content out of audit records`
- **Audit page:**
  - `returns 403 for members and viewers requesting the audit page`
  - `keeps audit entries after the acting user deletes their account`
- **The whole flow:** `completes an authenticated MCP task workflow with an audit trail`, one HTTP sequence from creation to deletion.

Finish with the full suite and static analysis:

```bash
php artisan test --compact
composer types:check
```

## Gotchas

- Counters use the default cache store (`CACHE_STORE=database` locally). No Redis needed.
- Restore `TEAMBOARD_MCP_RATE_LIMIT` after the demo, or every later lesson recording will hit 429.
- Times on the audit page are UTC.
