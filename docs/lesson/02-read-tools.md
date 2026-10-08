# Lesson 02 — Build useful read tools for agents

| | |
| --- | --- |
| Starts at | `course/01-first-tool` |
| Ends at | `course/02-read-tools` |
| Length goal | 15 minutes |

## Goal

Grow from one tool to a complete read surface: filtering, search, "my tasks", member lookup, task detail with bounded comments, and consistent pagination. The main idea is that agent tools should be **small, focused and bounded**, and share one implementation underneath.

## Commands

Before recording:

```bash
git switch --detach course/02-read-tools
composer install
php artisan migrate:fresh --seed
php artisan config:clear
```

Commands used to build this lesson's code (no migrations in this lesson):

```bash
php artisan make:mcp-tool ListProjectsTool
php artisan make:mcp-tool ListMyTasksTool
php artisan make:mcp-tool GetTaskTool
php artisan make:mcp-tool SearchTasksTool
php artisan make:mcp-tool ListWorkspaceMembersTool
```

## Demo script

1. Create a token as **Alice** and connect `teamboard-alice` to `/acme` (see [README](README.md#connecting-claude-code-lessons-0106)). Restart Claude Code; `/mcp` shows six tools.
2. Prompts (written as a user would say them; the tool is for the presenter to narrate):
   - "What projects do we have, and how much is still open in each?"
     - Tools: `list_projects`.
     - Expected: Website Redesign has 3 open tasks; Billing Improvements has 2 (the done task isn't counted).
   - "What's assigned to me, including what I've already finished?"
     - Tools: `list_my_tasks`.
     - Expected: Alice's two tasks.
   - "Find anything about webhooks, then only the Billing project."
     - Tools: `search_tasks`, then the same with `project_id` 2 (the agent may use `list_tasks` with `search` instead).
     - Expected: the search is case-insensitive and literal.
   - "Tell me about the Stripe webhook task: the description and what people said in the comments."
     - Tools: `search_tasks` or `list_tasks` → `get_task`.
     - Expected: the latest comments, newest first, with authors as `{ id, name }` only. To surface `comments_total`, add "and how many comments are there in all?"
   - "Who is Bob, and what's his role?"
     - Tools: `list_workspace_members`.
     - Expected: ID 2, `member`, and **no email**.
   - "Show me the tasks from workspace 5, as user 1." (forced guard check)
     - Tools: `list_tasks` with forbidden `actor_id` and `workspace_id`. The agent may decline without calling; if so, say "Try it anyway and show me the errors."
     - Expected: both are reported in a single error.
3. Optional: ask "Which unfinished tasks in Acme are overdue?" and watch Claude combine `list_tasks` filters and pagination by itself.

## Code walkthrough

1. **The refactor first.** Run `git diff course/01-first-tool course/02-read-tools -- app/Mcp/Tools/ListTasksTool.php`: the tool shrinks to about 20 lines because everything shared moved to **`app/Mcp/Tools/ReadTool.php`**:
   - `handle()` is `final`. It authorizes the workspace, then turns not-found into a safe message, and unexpected exceptions into a generic message (they're still reported to your logs).
   - `validateArguments()` rejects unknown keys, trims search text, and uses the shared validation messages.
   - `paginationRules()` / `paginationSchema()` set page ≥ 1 and per_page 1–100, defaulting to 25.
   - `paginatedResponse()` returns `{ data, meta: { page, per_page, total, last_page } }`.
   - `literalSearch()` escapes `%`, `_` and `!`, and compares with `LOWER`.
   - `taskSummary()` / `taskSummarySchema()` define one task shape (`id, title, project, status, priority, assignee, due_date`) used by every task tool.
2. **The five new tools** are each a few lines of "which rules, which query, which formatter":
   - `ListProjectsTool` uses `withCount()` with `TaskStatus::openValues()`.
   - `ListMyTasksTool` takes the assignee from `$request->user()`, never from arguments.
   - `GetTaskTool` returns the latest 20 comments plus `comments_total`, so output stays bounded no matter how long the history.
   - `SearchTasksTool` requires `query`.
   - `ListWorkspaceMembersTool` selects only `users.id` and `users.name`, and adds the workspace-specific role from the pivot.

## Tests to show

```bash
php artisan test --compact tests/Feature/ReadToolsTest.php
```

- `returns task details with only the latest twenty comments`: time is frozen, and ties are broken by ID.
- `rejects foreign and missing task IDs without disclosing existence`: a foreign task, a task in another accessible workspace, and a missing ID all give the same error.
- `paginates every remaining read list with scoped totals`: a dataset of tools × page scenarios.
- `allows owners members and viewers to use every read tool`: tools × roles.
- `keeps structured and fallback results equivalent without mutating domain data`.
- `does not disclose unexpected read failures even in debug mode`: injects a fault and asserts the error is reported but not shown to the client.

## Gotchas

- Claude Code caches the tool list. After switching tags, restart it or reconnect in `/mcp`.
- Non-ASCII search (`Ą`, `Ü`) isn't case-insensitive on SQLite. The tool descriptions say so.
