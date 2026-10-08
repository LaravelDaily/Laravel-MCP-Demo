# Lesson 03 — Let the agent create and update work

| | |
| --- | --- |
| Starts at | `course/02-read-tools` |
| Ends at | `course/03-write-tools` |
| Length goal | 15–20 minutes |

## Goal

Add five semantic write tools (`create_task`, `update_task`, `assign_task`, `move_task`, `add_comment`) that reuse the **same application actions and rules as the web UI**. The main idea: an MCP tool is just another delivery layer, like a controller. It never gets its own permission model or its own validation.

## Commands

Before recording:

```bash
git switch --detach course/03-write-tools
composer install
php artisan migrate:fresh --seed
php artisan config:clear
```

Commands used to build this lesson's code (no migrations):

```bash
php artisan make:mcp-tool CreateTaskTool
php artisan make:mcp-tool UpdateTaskTool
php artisan make:mcp-tool AssignTaskTool
php artisan make:mcp-tool MoveTaskTool
php artisan make:mcp-tool AddCommentTool
```

## Demo script

1. Connect `teamboard-alice` (Alice, `/acme`). `/mcp` shows 11 tools.
2. The workflow, one prompt at a time. Prompts are written the way a user would say them; the tool names are for the presenter to narrate. The agent's wording and extra read calls will vary.
   1. Prompt: "Add a task called 'Write onboarding email' to the Website Redesign project."
      - Tools: `list_projects` → `create_task`.
      - Expected: `todo` / `medium` by default.
   2. Prompt: "Give it the description 'Welcome email for new signups' and make it high priority."
      - Tools: `update_task`.
      - Point out that omitted fields stay unchanged.
   3. Prompt: "Assign it to Bob."
      - Tools: `list_workspace_members` → `assign_task`.
   4. Prompt: "I've started on it, so mark it as in progress."
      - Tools: `move_task`.
   5. Prompt: "Leave a comment saying 'Draft ready for review'."
      - Tools: `add_comment`.
   6. Prompt: "Show me everything about that task."
      - Tools: `get_task`.
      - Expected: the comment author is **Alice**, taken from the token, not from any argument.
3. Show the result in the browser: open the task as Alice. It's the same data, changed through the same actions.
4. Validation the agent can act on. A natural prompt usually won't reach the validation: "Create a task with no title, due 15-11-2026" makes the agent ask for a title and reformat the date itself. That is worth showing, then force the raw errors (forced prompt, the one place a tool name is deliberate):
   - Forced: "Call create_task for project 1 with an empty title and the due date '15-11-2026', exactly as I gave it, and show me the error."
   - Expected: field-level errors for the title and the `YYYY-MM-DD` format.
5. Ownership. Natural prompt first: "Mark the onboarding email task as done."
   - Tools: `move_task`. The agent routes status changes to the right tool by itself.
   - Then show the guard (forced): "Call update_task on that task with status done, and show me the error."
   - Expected: "The status field is not supported here." Status changes go through `move_task`. Rehearse this one: the agent may refuse to send a call it expects to fail.
6. The lead-in to lesson 04: connect **Charlie** (viewer) as `teamboard-charlie`. `/mcp` still lists all 11 tools.
   - Prompt: "Using teamboard-charlie, add a task called 'Viewer test' to the Website Redesign project." (Naming the server is a connection choice, not a tool.)
   - Tools: `create_task`.
   - Expected: "Your workspace role cannot create tasks." **The policy stops it, but Charlie shouldn't even see that tool.** That's the next lesson.
7. Clean up: delete "Write onboarding email" as Alice in the browser.

## Code walkthrough

1. **`app/Mcp/Tools/TeamBoardTool.php`**: the base class shared by reads and writes (the error handling, `validateArguments()`, `taskSummary()`, `actor()`, `taskDetails()`, `taskMetadataSchema()`). `ReadTool` now extends it.
2. **The write tools** follow one pattern: validate the arguments → resolve the record through `WorkspaceContext` → call the shared action from `app/Actions/Tasks` → format the result.
   - In `CreateTaskTool::execute()`, `app(CreateTask::class)(...)` is exactly what the Livewire project page calls.
   - Point at `app/Livewire/Projects/Show.php::createTask()` side by side.
3. **Validation reuse**: `TaskValidationRules` (`taskCreationRules`, `taskUpdateRules`, `assigneeExistsRule`), the same rules and messages for web and MCP.
4. **Annotations**: every write is `#[IsReadOnly(false)]`, `#[IsDestructive(false)]` and `#[IsIdempotent(false)]`.
   - Retrying `create_task` or `add_comment` creates duplicates, and the descriptions say so honestly.
5. **Policies with messages**: `app/Policies/TaskPolicy.php` and `CommentPolicy.php` return `Response::deny('Your workspace role cannot …')`. Agents get an error they can explain to the user.
6. **`AssignTaskTool`**: `assignee_id` is required but nullable, so unassigning is explicit (`null`), never "forgot the argument". Viewers can be assigned work; roles only limit who can *make* changes.

## Tests to show

```bash
php artisan test --compact tests/Feature/TaskWriteToolsTest.php
```

- **Creating and updating:**
  - `creates a task in the selected project for owners and members`
  - `updates only submitted task metadata`
  - `clears nullable task metadata with explicit null`
- **What `update_task` refuses:** `rejects status assignment and ownership changes through update_task`.
- **Comment author:** `adds a comment as the authenticated user`. A forged `user_id` is rejected.
- **Viewers:** `forbids viewer calls to every write operation`, which asserts the exact deny messages.
- **Tenant isolation:** `rejects foreign and missing write records without disclosing existence`, with the same not-found error for foreign and missing IDs.
- **Web and MCP agree:** `keeps web and MCP metadata validation equivalent`. The same invalid input fails the same way in both.

## Gotchas

- Claude Code asks you to approve each write tool call. Approve them on camera; it's part of the safety story.
- The annotations are hints for the client. They don't replace the policies.
