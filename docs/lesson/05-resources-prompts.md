# Lesson 05 — Resources and prompts: ship ready-made workflows with your server

| | |
| --- | --- |
| Starts at | `course/04-permissions` |
| Ends at | `course/05-resources-prompts` |
| Length goal | 8–10 minutes |

## Goal

So far **the model** decided when to call our tools. MCP has two more primitives, and both are about the **user** being in control:

| Primitive | Who triggers it | What it is | TeamBoard example |
| --- | --- | --- | --- |
| Tool | The model | An action or a query | `list_tasks`, `create_task` |
| **Prompt** | The user, as a slash command | A ready-made workflow the server provides | `/project_review billing` |
| **Resource** | The app or user attaches it, or a prompt asks for it | Read-only data at a URI | `project://2/instructions` |

The two ideas to leave viewers with:

- **A prompt is a slash command your app ships into every user's Claude.** The server owner writes the workflow once, with the domain rules ("overdue means strictly before today and not done", "don't change anything"), and every user gets it without writing or sharing a prompt themselves.
- **A resource is a document Claude can read.** Here it's the project's "AI instructions" field: a CLAUDE.md that lives in the web app. A PM edits it in the browser, and every teammate's Claude picks it up.

The server never calls an LLM. It only provides text and data, and the client does the work.

Be honest about resources: they're the least-used primitive, and client support varies. Users rarely attach them by hand; in practice something else, like our prompt, pulls them in.

## Commands

Before recording:

```bash
git switch --detach course/05-resources-prompts
composer install
php artisan migrate:fresh --seed
php artisan config:clear
```

Re-seed shortly before recording: due dates are relative to the seed time, and the review depends on today's date.

Commands used to build this lesson's code (no migrations):

```bash
php artisan make:mcp-resource ProjectInstructionsResource
php artisan make:mcp-prompt ProjectReviewPrompt
```

Connect `teamboard-alice` (Alice, `/acme`).

## Demo script

1. **The prompt first, with no explanation yet.**
   - Type `/` and point at **`/teamboard-alice:project_review (MCP)`** in the list: "I didn't write this slash command. The TeamBoard server gave it to me when I connected."
   - Run `/mcp__teamboard-alice__project_review billing`.
     - The user types a project **name**, not an ID. Part of a name works when it matches one project.
   - Watch the tool calls: Claude reads the `project://2/instructions` resource, then `list_tasks` for `todo` and `in_progress`, then `get_task` for details.
   - Expected report:
     - **Overdue:** "Investigate failed Stripe webhook" (due 3 days ago, still in progress).
     - **High priority:** the same Stripe task.
     - **Likely blockers,** quoted from the comments: waiting on the failed delivery logs, and support reports of duplicate charges.
     - Three next actions.
   - Point out that no write tool was called. The prompt told Claude the review is read-only.
2. **Now explain what happened.**
   - "That first thing Claude read was a *resource*." Open **Billing Improvements** in the browser and point at the **AI instructions** field: acceptance criteria before work starts, and bug reports must include reproduction steps and the affected customer segment.
   - On the **Projects** page, edit Billing Improvements and add a line to **AI instructions** (for example "- Every bug needs an owner."), then run `/mcp__teamboard-alice__project_review billing` again, and show the new rule appearing in the review. No code, no repo change.
   - Change the line back afterwards.
3. **Quick aside (20 seconds):** you can attach a resource by hand with `@teamboard-alice:project://2/instructions`, but you'll rarely do it. Templates often don't show up in the `@` autocomplete, so you'd have to type the URI.
4. **Isolation, one line:** `/mcp__teamboard-alice__project_review payroll` (a Globex project) gives "No project in the current workspace matches "payroll"." The same workspace rules from lesson 04 apply to prompts and resources.

## Code walkthrough

1. **`app/Mcp/Prompts/ProjectReviewPrompt.php`**: the most interesting file, so spend most of the time here.
   - `arguments()` declares one required `project` argument. **Prompt arguments are typed by humans**, so it takes a name or an ID, not just a database ID.
   - `findProject()`: an ID or a case-insensitive exact name wins; otherwise a unique part of a name. It only searches `WorkspaceContext::projects()`, so a Globex project and a missing one give the same error. Several matches give "use a longer name or the project ID".
   - `reviewInstructions()` is the recipe: today's date, the resource URI, the exact read tool names, the definitions (overdue is *strictly before today and not done*; blockers are inferences, so quote the evidence), and the closing "read-only, don't change anything" rule.
2. **`app/Mcp/Resources/ProjectInstructionsResource.php`**: a quick look.
   - The URI template `project://{project}/instructions`: one class for every project. URIs use IDs, which is fine, because users don't type them; the prompt does.
   - It returns JSON with `project: { id, name, description }` and nullable `instructions`, resolved through `WorkspaceContext`.
3. **`TeamBoardServer`**: `$resources` and `$prompts` sit next to `$tools`, and the server instructions mention both.

## Tests to show

```bash
php artisan test --compact tests/Feature/ProjectReviewPromptTest.php tests/Feature/ProjectInstructionsResourceTest.php
```

- `resolves the review project by ID or by name`: ID, exact name, different case with spaces, and a unique part of the name.
- `prefers an exact name over a partial match`: "website" picks "Website", not "Website Redesign".
- `rejects missing ambiguous and foreign review project arguments`: ambiguous parts, unknown names, `%` as input, and names from other workspaces.
- `generates a review prompt for the selected workspace project`: time is frozen, so the date and the overdue definition are exact.
- `does not mutate data when reading resources or requesting prompts`.

## Gotchas

- **Check a multi-word name before recording:** run `/mcp__teamboard-alice__project_review Billing Improvements` once. If Claude Code splits the words into separate arguments, stick to a single word like `billing` on camera.
- If Claude skips reading the resource (the prompt allows "say so and continue" for clients that can't read resources), run the prompt again. That's the client's choice, not something the server can force.
- After editing the AI instructions in the browser, there's no caching to clear: the resource is read fresh on every request.
