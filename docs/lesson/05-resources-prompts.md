# Lesson 05 — Tools, resources, and prompts in practice

| | |
| --- | --- |
| Starts at | `course/04-permissions` |
| Ends at | `course/05-resources-prompts` |
| Length goal | 15 minutes |

## Goal

Show the two other MCP primitives:
- A **resource template** (`project://{project}/instructions`), which exposes each project's AI instructions as context.
- A **prompt** (`project_review`), a reusable review recipe.

The main idea is that the server only *provides* context and instructions. The client decides when to read them and does the actual work. The server never calls an LLM.

## Commands

Before recording:

```bash
git switch --detach course/05-resources-prompts
composer install
php artisan migrate:fresh --seed
php artisan config:clear
```

Commands used to build this lesson's code (no migrations):

```bash
php artisan make:mcp-resource ProjectInstructionsResource
php artisan make:mcp-prompt ProjectReviewPrompt
```

Connect `teamboard-alice` (Alice, `/acme`), and optionally `teamboard-charlie` to show that viewers can use both.

## Demo script

1. Show the source data first: in the browser, open **Website Redesign** and point at the **AI instructions** field.
2. Resource:
   - Type: `Summarize @teamboard-alice:project://1/instructions`.
   - Expected: the acceptance-criteria and reproduction-steps conventions.
   - Templates may not appear in the `@` autocomplete, so type the full URI.
3. Prompt:
   - Type `/` and pick **`/teamboard-alice:project_review (MCP)`**, or run `/mcp__teamboard-alice__project_review 1`.
   - Claude reads the instructions resource, pages through `list_tasks` for `todo` and `in_progress` tasks, and opens details with `get_task`.
   - It reports:
     - **overdue** tasks (the Stripe webhook task is overdue: its due date is before today and it isn't done);
     - **high-priority** and **unassigned** tasks (Prepare launch checklist);
     - **likely blockers** inferred from comments;
     - then three next actions.
   - It must not call any write tool.
4. Isolation: `Summarize @teamboard-alice:project://4/instructions` (a Globex project).
   - Expected: "The project was not found in the current workspace.", with no Globex text.
   - `/mcp__teamboard-alice__project_review 4` gives the same kind of error.
5. Optional, with the Inspector CLI: `resources/templates/list` and `prompts/get --prompt-name project_review --prompt-args project_id=1`. The exact commands are in the main README under "Inspector". Show the raw message the server returns.

## Code walkthrough

1. **`app/Mcp/Resources/ProjectInstructionsResource.php`**:
   - It implements `HasUriTemplate`, with the URI template `project://{project}/instructions`.
   - It's one class for all projects, not a static resource per project, so `resources/list` stays empty.
   - It validates the `{project}` variable and resolves it through `WorkspaceContext`.
   - It returns JSON with exactly `project: { id, name, description }` and nullable `instructions`, with `#[MimeType('application/json')]`.
   - Foreign, missing and malformed IDs all raise the same validation error, which becomes JSON-RPC `-32602`.
2. **`app/Mcp/Prompts/ProjectReviewPrompt.php`**:
   - `arguments()` declares the required `project_id`. Prompt arguments are always strings, so it accepts `"1"`.
   - `reviewInstructions()` puts in today's date (UTC), the resource URI, the exact read tool names, and the definitions:
     - overdue means *strictly before today and not done*;
     - blockers are inferences, so quote the evidence.
   - It ends with an explicit "read-only, don't change anything" rule.
3. **`TeamBoardServer`**: `$resources` and `$prompts` are registered next to `$tools`, and the server instructions mention them.

## Tests to show

```bash
php artisan test --compact tests/Feature/ProjectInstructionsResourceTest.php tests/Feature/ProjectReviewPromptTest.php
```

- **Resource discovery:** `discovers the project instructions resource template`, over a real HTTP `resources/templates/list` call.
- **Resource access:**
  - `reads project instructions for every workspace role`
  - `rejects foreign missing and malformed project resource URIs`
- **Prompt content:** `generates a review prompt for the selected workspace project`. Time is frozen, so the date and the overdue definition are exact.
- **No side effects:** `does not mutate data when reading resources or requesting prompts`.

## Gotchas

- How clients use resources and prompts varies. Some never read resources automatically. The prompt tells Claude what to read, and the server doesn't force anything.
- The review reflects today's date. Because demo due dates are relative to the seed time, re-seed shortly before recording so the "overdue" examples are right.
