# Lesson 04 — Permissions, tenant isolation, and dangerous tools

| | |
| --- | --- |
| Starts at | `course/03-write-tools` |
| Ends at | `course/04-permissions` |
| Length goal | 15–20 minutes |

## Goal

Add the only destructive tool, owner-only `delete_task`, and make the tool list depend on the user's role in the URL's workspace. The main idea: **hiding tools is usability, policies are security**. Show both layers, and prove a guessed hidden tool still can't change data.

## Commands

Before recording:

```bash
git switch --detach course/04-permissions
composer install
php artisan migrate:fresh --seed
php artisan config:clear
```

Commands used to build this lesson's code (no migrations):

```bash
php artisan make:mcp-tool DeleteTaskTool
```

Setup for the demo: create tokens for **Alice, Bob, Charlie and Dana** (each logged in separately), and add these servers:

| Server name | Token | URL ends with |
| --- | --- | --- |
| `teamboard-alice` | Alice | `/acme` |
| `teamboard-bob` | Bob | `/acme` |
| `teamboard-charlie` | Charlie | `/acme` |
| `teamboard-dana-acme` | Dana | `/acme` |
| `teamboard-dana-sandbox` | Dana | `/sandbox` |

## Demo script

1. Run `/mcp` and open each server's tool list:

   | Server | Tools |
   | --- | --- |
   | `teamboard-alice` | 12 (including `delete_task`) |
   | `teamboard-bob` | 11 (no `delete_task`) |
   | `teamboard-charlie` | 6 (reads only) |
   | `teamboard-dana-acme` | 12 |
   | `teamboard-dana-sandbox` | 6 |

   **The same token** gives Dana different tools on different URLs.
2. Deletion as an owner:
   - "Use teamboard-alice create_task in project 1 titled 'Temporary task', add_comment 'to be deleted', then show its ID."
   - "Use teamboard-alice delete_task on that ID only, then call get_task on it."
   - Expected: `{ deleted: true, task: { id, title } }`, then "The record was not found in the current workspace."
   - Point out that Claude Code asks for confirmation. The tool's description tells the client to confirm with the user, and it's marked `destructiveHint: true`.
3. A guessed hidden tool:
   - "Use teamboard-bob list_tasks with per_page 1, then call delete_task on that task ID."
   - Expected: the tool is unknown ("Tool [delete_task] not found."), and `get_task` shows the task is still there.
4. Viewer: "Use teamboard-charlie create_task in project 1." Expected: no such tool, and no task is created.
5. Role change live (optional):
   1. Make Bob a viewer: `php artisan tinker --execute 'App\Models\Workspace::where("slug","acme")->first()->memberships()->where("user_id",2)->first()->update(["role"=>"viewer"]);'`
   2. Reconnect `teamboard-bob` in `/mcp`: 6 tools.
   3. Change it back to `"member"`.

## Code walkthrough

1. **`app/Mcp/Tools/DeleteTaskTool.php`**:
   - It captures `{ id, title }` before calling the shared `DeleteTask` action.
   - `#[IsDestructive(true)]`, and not idempotent: a repeated call reports "not found".
   - The description says it's permanent and that the client should confirm with the user.
2. **`shouldRegister()`** on every write tool calls `TeamBoardTool::actorCanInWorkspace()`, which asks the policy about the actor's role **in the URL's workspace**. It runs on every request, so nothing is cached.
3. **`app/Policies/WorkspacePolicy.php`**: `contribute` (owner/member) and `deleteTasks` (owner) are workspace-level questions for discovery.
   - **`TaskPolicy::delete`** still authorizes each deletion: "Your workspace role cannot delete tasks."
   - Two layers, and the second one doesn't trust the first.
4. **Tenant isolation recap**: every tool resolves records through `WorkspaceContext`, so a task ID from Globex or from Dana's Sandbox is "not found" on the Acme URL, with the same message as a missing ID.

## Tests to show

```bash
php artisan test --compact tests/Feature/ToolDiscoveryTest.php
```

- `discovers all tools for owners`: the exact set of 12 tools over real HTTP.
- `hides delete_task from members`: 11 tools, and a guessed HTTP deletion leaves the data unchanged.
- `hides every write tool from viewers`: all six guessed write calls fail with nothing changed.
- `uses the selected workspaces role for tool discovery`: Dana with one token, owner, then viewer, then owner again.
- In `TaskWriteToolsTest`: `deletes tasks and comments only for owners`, and `forbids viewer calls to every write operation`, which calls each tool's handler directly to prove the policy blocks it even without discovery.

## Gotchas

- Clients don't get notified when a role changes. Reconnect (or restart Claude Code) to refresh the tool list.
- Annotations are advisory. Some clients may not show a confirmation for destructive tools, which is why the policy check matters.
