# TeamBoard: implementation phases and course checkpoints

## Purpose and how to use this document

Build TeamBoard, a small multi-tenant Laravel SaaS that exposes projects, tasks, and comments to AI clients through the official Laravel MCP package. The course teaches how to add MCP to an existing SaaS, with authentication, tenant isolation, authorization, agent-friendly tools, and meaningful tests.

This document is the complete product and implementation brief. An implementer does not need the original brainstorm or demo specification. Phases are cumulative: read the shared requirements and completed phases in this document before implementing the next one. Technical API details must come from the installed packages, Laravel Boost, Context7, and official documentation; do not invent framework methods.

Implement phases 1–6 to complete the core demo. Phase 7 is a separately scheduled OAuth course extension. Work in separate sessions at phase boundaries, with smaller commits inside each phase. A session boundary does not require a separate branch.

### Starting point and implementation rules

- Preserve the existing Laravel Livewire starter kit, registration, login, verification, and account settings. Do not reinstall it or replace its frontend.
- At planning time, direct dependencies include Laravel 13.34.0, Livewire 4.4.7, Flux 2.20.1, Laravel MCP 1.0.1, Pest 5.3.0, Pint, and Larastan. Sanctum and Passport are not installed. Recheck installed versions before implementation.
- Follow repository `AGENTS.md`, applicable `.ai/rules` when present, existing sibling-file conventions, and relevant skills. Use Laravel Boost for ecosystem documentation and database inspection; use Context7 for library documentation according to repository instructions.
- Use Artisan generators with `--no-interaction` for Laravel classes and tests. Follow the installed Pest test syntax and existing test organization.
- Use normal Eloquent relationships, enums, Laravel validation, policies, and explicit workspace-scoped queries. Extract small application actions or shared validation only where web and MCP reuse warrants them.
- Do not introduce repositories, DTO layers, a generic task manager, a tenancy package, a separate MCP permission model, or a generic `manage_task(action, payload)` tool.
- Sanctum in phase 3 and Passport in phase 7 are the only planned dependency additions. Follow repository authorization requirements before dependency changes; do not add other dependencies or testing plugins without approval.
- Do not deploy, publish, push commits, or push tags as part of implementation unless separately authorized. Local commits and the checkpoint tags below are required when implementation is authorized.
- The local demo URL is `https://teamboard.test` (a Laravel Herd site secured with `herd secure`); use it for `APP_URL`, MCP endpoint examples, and README instructions. Students without Herd substitute their own HTTPS URL.
- Keep UI styling consistent with the existing Livewire/Flux starter kit. Reuse existing layouts and components. Avoid elaborate design work.
- Out of scope: billing, Stripe integration, invitations, membership management UI, workspace creation/onboarding, notifications, websockets, attachments, tags, subtasks, calendars, external integrations, public REST APIs, impersonation, AI-generated content, streaming demonstrations, and MCP Apps. Stripe may appear in seeded task titles only.

### Shared security and application contracts

1. Authentication establishes the user; workspace membership establishes tenant access; policies establish allowed operations. Keep these concerns separate.
2. Every project, task, comment, count, search result, assignee lookup, resource, prompt, and audit query is restricted to the **selected workspace**, not all workspaces accessible to the user.
3. Resolve and validate workspace membership before looking up tenant-owned records. Query records through the selected workspace or an explicit workspace scope. Do not fetch a global task and rely only on a later policy check.
4. Apply the same policy and business rules through Livewire, shared application operations, and MCP. Hidden controls and hidden tools are usability features, not authorization boundaries.
5. For web requests, inaccessible tenant records return 404; insufficient permissions within an accessible workspace return 403. MCP returns useful protocol/tool errors for the equivalent cases, without exposing foreign record names or existence. Missing and inaccessible IDs must have equivalent public errors.
6. Never accept a caller-supplied actor, comment author, workspace ownership, or arbitrary model attributes. Reject unsupported MCP arguments rather than merging an unrestricted payload into a model.
7. Use normal deletion. Deleting a project deletes its tasks and their comments; deleting a task deletes its comments. Audit records introduced later survive deletion of their target entity.
8. No-workspace users receive an empty state. Do not fabricate memberships or silently let them use another user's workspace.

### Shared test and completion rules

- The test names and assertions in each phase are acceptance contracts. Use the names as Pest descriptions, adapting grammatical prefixes to neighboring tests. Closely related cases can use named datasets.
- Prefer feature tests with real database records and existing factories. Preserve the repository's existing database-refresh convention and existing tests. Do not depend on seed IDs, test execution order, real time, a running AI client, or external network requests.
- Freeze or control time for due-date, rate-limit, audit-order, and token-expiration tests. Choose small fixtures that distinguish correct behavior from cross-workspace leakage.
- Successful mutation tests assert the response and exact persisted changes. Denial/validation tests assert useful errors and no database changes. From phase 6 onward, they also assert the expected audit effect.
- Put the complete role matrix at the policy/application boundary. At Livewire and MCP boundaries, prove the boundary actually enforces it; use datasets where setup and assertions are identical. Avoid repeating every validation-value matrix at every layer.
- Use the MCP package's primitive test helpers for tool/resource/prompt behavior. Also send real HTTP JSON-RPC requests for authentication, workspace resolution, discovery, middleware, and rate-limit contracts. `actingAs` primitive tests alone do not verify bearer authentication.
- Add tests with their feature commits, not in a final testing-only commit. Run the narrowest affected tests after changes. Before checkpoint tags, run the accumulated relevant tests and existing project static analysis. Format modified PHP using `vendor/bin/pint --dirty --format agent`.
- Before the core-completion and OAuth-completion tags, run `php artisan test --compact`, the existing static-analysis command, and `npm run build` if frontend assets changed. Resolve regressions in existing starter-kit tests without deleting them or weakening assertions.
- Update the README incrementally at runnable checkpoints. This document authorizes that required setup documentation; do not create unrelated documentation artifacts.
- A phase is complete only when its implementation, tests, documentation, and specified commits/checkpoints are complete. Report what was tested and any real-client verification still outstanding.

## Phase 1 — Domain, demo data, workspace context, and policies

**Outcome:** a tested application foundation with tenant isolation and permissions, before adding TeamBoard pages or MCP tools.

### Domain and database

Keep the existing `users` table and authentication model. Add:

| Table | Fields and constraints |
| --- | --- |
| `workspaces` | `id`, `name`, unique `slug`, timestamps |
| `workspace_user` | `workspace_id`, `user_id`, `role`, timestamps; unique workspace/user pair; foreign keys |
| `projects` | `id`, `workspace_id`, `name`, nullable text `description`, nullable text `instructions`, timestamps |
| `tasks` | `id`, `project_id`, nullable `assignee_id` referencing users, `title`, nullable text `description`, `status` default `todo`, `priority` default `medium`, nullable date `due_date`, timestamps |
| `comments` | `id`, `task_id`, `user_id` referencing the author, text `body`, timestamps |

Use role enum values exactly `owner`, `member`, `viewer`; status values exactly `todo`, `in_progress`, `done`; priority values exactly `low`, `medium`, `high`. PHP enum case names use TitleCase. A task is completed only when its status is `done`; there is no separate completed flag.

Define relationships: users belong to many workspaces with a membership role; workspaces have members and projects and tasks through projects; projects belong to workspaces and have tasks; tasks belong to projects and optionally assignees and have comments; comments belong to tasks and authors. Cast membership roles and task status/priority to their enums, and due dates to dates. Use a custom membership pivot only if needed for the role cast, rather than inventing another membership table.

Add useful factories for Workspace, Project, Task, and Comment. Keep UserFactory. Task states include `todo`, `inProgress`, `done`, and `highPriority`. Factories must allow explicit coherent tenant relationships; do not generate an assignee from a different workspace by default.

### Workspace context

Create a small request-scoped workspace context, with a clear way to obtain the current workspace and its scoped projects/tasks. Do not use a global singleton that retains user/workspace state across requests.

- Web: store selected workspace ID in the session. With no selection, choose the user's first membership ordered by workspace ID. A stale or now-inaccessible selection is cleared and falls back to the first remaining membership. No memberships means no current workspace.
- Switching: accept only a workspace the current user belongs to. Reject an explicit foreign selection as not found (404), with the same error as a missing workspace; never save it in the session.
- MCP extension seam: allow a later request adapter to set a validated explicit workspace. MCP must never derive its workspace from the browser's selected session value. The explicit workspace lives only for the current request: do not keep it in Laravel's `Context` facade, a container singleton, or any state that survives to another request or a queued job. A non-member workspace is rejected as not found.
- Queries: workspace context restricts projects and tasks to the selected workspace, including when the user has another valid membership elsewhere.

### Authorization matrix

Create WorkspacePolicy, ProjectPolicy, TaskPolicy, and CommentPolicy, using Laravel's normal policy registration/discovery conventions.

| Ability within the selected workspace | Owner | Member | Viewer | Non-member |
| --- | --- | --- | --- | --- |
| View workspace, members, projects, tasks, comments | Yes | Yes | Yes | No |
| Create/edit/delete projects | Yes | No | No | No |
| Create/edit tasks, assign/unassign, change status | Yes | Yes | No | No |
| Add comments | Yes | Yes | No | No |
| Delete tasks | Yes | No | No | No |
| View MCP audit page, introduced in phase 6 | Yes | No | No | No |

Any workspace member, including a viewer, may be an assignee. Roles control ability to mutate; they do not restrict who can be assigned work. Comment authors always come from authenticated identity. Comment editing/deletion is out of scope.

### Deterministic demo data

Seed verified local-demo users, all with password `password`:

| Account | Memberships |
| --- | --- |
| Alice, `alice@example.com` | Acme owner |
| Bob, `bob@example.com` | Acme member |
| Charlie, `charlie@example.com` | Acme viewer |
| Dana, `dana@example.com` | Acme owner and Sandbox viewer |
| Eve, `eve@example.com` | Globex owner |

Use workspace slugs `acme`, `sandbox`, and `globex`. None of Alice, Bob, Charlie, or Dana belongs to Globex. Dana demonstrates workspace switching and different roles across connections.

Acme projects and tasks:

- Website Redesign: Redesign pricing page; Fix mobile navigation; Prepare launch checklist.
- Billing Improvements: Investigate failed Stripe webhook; Add invoice download; Improve subscription cancellation flow.

Include a mix of all task statuses and priorities, tasks assigned to Alice/Bob, an unassigned task, comments, a past-due unfinished task, a future-due task, and a completed task with a past due date. Calculate demo due dates relative to seed execution so the review demonstration stays useful. Add project instructions, such as requiring acceptance criteria and reproduction steps for bugs.

Give Globex and Sandbox distinct projects/tasks with recognizable titles to reveal accidental leakage. Keep fixtures predictable after a fresh local migrate-and-seed; do not commit secrets or generated tokens. Do not automatically run destructive resets against an existing database; seeding again when the demo data exists is a no-op with a warning.

### Expected automated tests

| Test name | Expected assertions |
| --- | --- |
| `allows a user to belong to multiple workspaces with different roles` | One user has two memberships; each role is correctly resolved without applying one workspace's role to the other. |
| `resolves project tasks and comment authors through their relationships` | Known project/task/comment fixtures resolve the correct workspace, project, optional assignee, and author. |
| `creates tasks with todo status and medium priority by default` | Persisted defaults and enum-cast values are correct; nullable assignment/due date remain null. |
| `selects the first membership when no workspace is selected` | Context returns the lowest-ID accessible workspace and persists the selection. |
| `uses the selected workspace from the session` | Context returns the explicitly selected accessible workspace. |
| `falls back when the selected membership has been removed` | Invalid session selection is replaced by an accessible membership; no foreign data is exposed. |
| `has no current workspace when the user has no memberships` | Context returns its documented empty result without loading unrelated records. |
| `rejects selection of a workspace the user does not belong to` | Selection fails; previous session value remains valid and unchanged. |
| `scopes records to the selected workspace even with another valid membership` | Project/task lookups exclude the user's other workspace, not just non-member workspaces. |
| `does not carry workspace context into another request` | Consecutive request resolutions for different users/workspaces return their own context; no cached tenant or role leaks. |
| `enforces the workspace role permission matrix` | Dataset covers every ability/role in the matrix, including non-member denials and relationships owned by another workspace. |
| `seeds recognizable isolated workspaces and usable demo accounts` | Expected accounts/memberships/projects/tasks/instructions exist; users are verified; password authentication succeeds; Acme users have no Globex membership; seeded due-date/status scenarios exist. |

### Commits and exit criteria

1. **C01 — `Add TeamBoard domain and demo data`**: schema, models, enums, factories, seed data, domain/seed tests, README accounts.
2. **C02 — `Add workspace context and authorization policies`**: context, scoped access, policies, context/policy tests.

No course tag yet. All security foundations must work before phase 2.

## Phase 2 — The existing SaaS baseline

**Prerequisite:** phase 1. **Outcome:** a small usable Livewire application that becomes the course starting project.

### Pages and behavior

- Keep authenticated/verified access aligned with the starter kit. Guests follow the existing login flow.
- Workspace switcher lists only the user's memberships, persists a valid selection, and navigates back to the dashboard so a project/task page cannot retain the old workspace's record.
- Dashboard shows workspace name, project count, open-task count (`todo` plus `in_progress`), and the current user's assigned unfinished tasks. All figures use the current workspace. Show a useful empty state for users with no memberships.
- Projects index lists the current workspace's projects. Owners can create, edit, and delete projects, including description and AI instructions. Members/viewers can read only.
- Project detail shows name, description, instructions, and tasks. Task detail shows title, description, project, status, priority, assignee, due date, and comments.
- Owners/members can create/edit tasks, assign/unassign them, change status/priority/due date, and add comments. Owners can delete tasks. Viewers have read-only access.
- Use simple forms or modals, ordinary paginated lists, existing Flux components, and server-side Livewire state. Reauthorize and scope each action; mounting a valid component once is insufficient.
- Route binding and Livewire record IDs must both enforce selected-workspace scoping. Re-resolve records for mutations; do not trust a client-supplied project or workspace ID.
- Delete operations require a simple UI confirmation. Do not create a bulk-delete feature.
- Render free-text content escaped, including project instructions and comments.

### Shared application mutations and validation

Create focused shared operations for task creation, metadata editing, assignment, status changes, commenting, and deletion where necessary for later MCP reuse. They enforce policy authorization, selected-workspace ownership, and assignee membership. Keep transport response formatting outside these operations. Project mutations may remain simple web-only logic.

Use these validation contracts through web and later MCP:

- Project name/task title: trimmed, non-empty string, maximum 255 characters.
- Project description/instructions and task description: nullable strings, maximum 10,000 characters.
- Comment body: trimmed, non-empty string, maximum 5,000 characters.
- IDs: positive integers for records resolved within the selected workspace.
- Status/priority: exactly the enum values from phase 1.
- Due date: nullable strict `YYYY-MM-DD`; past dates are allowed.
- Assignee: nullable user who belongs to the selected workspace; allow viewers as assignees.
- Creation defaults: task status `todo`, priority `medium`.
- Partial metadata update: omitted fields are unchanged; explicit null clears description/due date; title/priority cannot be null. At least one editable field is required. Project ownership cannot be changed through task editing.
- Assignment and status changes are separate application operations, although a web edit form may coordinate them in one transaction. Invalid edits must not partly save a task.

### Expected automated tests

| Test name | Expected assertions |
| --- | --- |
| `redirects guests to login for TeamBoard pages` | Dashboard/projects/task detail use the existing unauthenticated browser redirect. |
| `shows an empty dashboard for a user with no workspace` | Empty-state text appears; no tenant records or mutation controls appear; no error is thrown. |
| `switches workspace and renders only its dashboard data` | Session changes, navigation returns to dashboard, counts and assigned tasks reflect only the new workspace. |
| `rejects a forged workspace switch` | Foreign selection fails; session and subsequent displayed data remain in the permitted workspace. |
| `counts unfinished tasks and lists only the current users unfinished assignments` | Known counts exclude done tasks and other workspaces; assignment list excludes other assignees. |
| `allows owners to create edit and delete projects` | Correct fields persist under current workspace; deletion removes the target project's tasks/comments and preserves other projects. |
| `forbids project mutations by members and viewers` | Direct Livewire action dataset returns authorization failures; database is unchanged. |
| `renders project and task details from the selected workspace` | Expected fields/comments appear; foreign project/task titles do not. |
| `returns 404 for foreign project and task routes` | Guessed foreign IDs and records in another accessible but inactive workspace return not found. |
| `creates and edits tasks for owners and members` | Allowed role dataset persists correct metadata, status, priority, assignment, and due date. |
| `forbids viewer task mutations and comments` | Direct calls for create/edit/assign/move/comment/delete fail; no task or comment changes. |
| `allows task deletion only for owners` | Owner deletes task/comments; member denial preserves both; another project's task remains. |
| `rejects task creation and editing with invalid values` | Named dataset covers blank/oversized title, invalid enums/date, oversized description; actionable field errors; no partial save. |
| `rejects an assignee outside the current workspace` | Create/assignment fails with the membership message; task/assignee remains unchanged. |
| `allows assignment to a viewer and explicit unassignment` | A workspace viewer can be assigned; null removes assignment. |
| `preserves omitted metadata and clears explicit nullable fields` | Only submitted fields change; explicit null clears description/date; empty updates and null title/priority fail. |
| `creates comments with the authenticated author` | Body/task persist; author is the actor even if forged author input is attempted. |
| `rejects blank and oversized comments` | Field errors appear and no comment is persisted. |
| `rejects forged record IDs during Livewire mutations` | Foreign task/project/comment-parent IDs cause not-found errors; no writes in either workspace. |
| `rechecks permission when membership changes after a component mounts` | Removing membership or downgrading role before an action prevents the write. |
| `escapes user provided project task and comment content` | Dangerous text displays escaped; executable raw markup is absent, including AI instructions. |

### Commits and exit criteria

3. **C03 — `Add workspace switching, dashboard, and projects`**: switcher, dashboard, project pages/actions and tests.
4. **C04 — `Add task management and comments`**: task/comment UI, shared mutations/validation and tests, baseline README setup.

After C04 and baseline checks, create **`course/00-teamboard-baseline`**. The application must work without MCP. This tag is the starting repository state supplied to students.

## Phase 3 — Authentication, first MCP tool, and complete read access

**Prerequisite:** phase 2. **Outcome:** an authenticated remote MCP server operating in an explicit workspace, first with one tool and then the complete read surface.

### Transport and identity

- Install/configure Sanctum using current official instructions, as a minimal install: require the package, publish its configuration and migration, and add API tokens to the user model. Do not run `install:api` or add `routes/api.php`; the MCP endpoint is the only token-authenticated surface. Preserve existing browser authentication. Do not implement custom bearer-token parsing or hard-code tokens.
- Add a minimal authenticated **MCP access** settings page (its own settings tab and route, not part of the Security page) to create and revoke named MCP personal access tokens. Tokens do not expire; revocation is the control, and the README says so. Names are trimmed, required strings of at most 100 characters. Show the plaintext token only on creation. Do not expose stored hashes, other users' tokens, or a persistent plaintext token list. Keep the token usable for MCP without inventing owner/member/viewer token abilities.
- Register TeamBoard's web MCP server in `routes/ai.php` at `/mcp/workspaces/{workspace}` using the workspace slug. Use installed Laravel MCP registration APIs, Sanctum authentication, and a middleware/adapter that validates membership and sets request workspace context.
- Authenticate before resolving/disclosing workspace details. Missing/invalid/revoked bearer token returns HTTP 401. An authenticated user requesting a nonexistent or non-member workspace gets HTTP 404 without workspace data.
- Bearer identity comes from the MCP request's authenticated user. Actor IDs are not tool arguments. UI session workspace selection never overrides the workspace in the MCP URL.
- Set useful server name/version/instructions describing the workspace boundary and tool purpose. Do not claim tools execute project instructions automatically.

### Read tool contracts

Use names exactly as below, clear descriptions, strict input schemas, Laravel validation, and read-only/non-destructive annotations. Reject unsupported arguments. Use structured results with a JSON text fallback through the package's supported response API. Avoid serializing entire models.

All list tools use optional `page` (positive integer, default 1) and `per_page` (1–100, default 25), ordered by ascending ID. Return `{ data: [...], meta: { page, per_page, total, last_page } }`, with total/count metadata scoped to the selected workspace and filters. Pages past the end return empty data. Search strings are trimmed, non-empty when supplied, maximum 200 characters, and matched literally as substrings rather than interpreted as SQL wildcard patterns. Use ordinary database comparisons; do not add a search engine.

| Tool | Inputs beyond pagination | Result |
| --- | --- | --- |
| `list_tasks` | Optional `project_id`, `status`, `priority`, `assignee_id`, `search` | Task summaries filtered conjunctively; search matches title or description. |
| `list_projects` | Optional `search` matching project name/description | `id`, `name`, `description`, `open_task_count`; done tasks excluded from the count. |
| `list_my_tasks` | Optional `status`, `priority` | Task summaries assigned to the actor; default includes all statuses. |
| `get_task` | Required `task_id`; no pagination | Task summary plus description and bounded comments, as described below. |
| `search_tasks` | Required `query`; optional `status`, `project_id` | Task summaries matching title or description; same pagination contract. |
| `list_workspace_members` | Optional `search` matching name only | `id`, `name`, `role`; no email, password, token, or pivot metadata. |

A task summary contains exactly `id`, `title`, `project: { id, name }`, `status`, `priority`, `assignee: null | { id, name }`, and `due_date: null | YYYY-MM-DD`. `get_task` adds `description`, `comments`, and `comments_total`. Comments include `id`, `body`, `author: { id, name }`; return the latest 20 ordered by creation time descending and ID descending. Do not return timestamps, hidden attributes, pivot fields, or unbounded histories.

Validate project/assignee filters against the selected workspace, not global existence. A foreign filter must not silently expand scope or disclose a foreign name. Invalid enums/page sizes/dates and missing/blank search queries receive actionable errors. Optional filters can be omitted; IDs cannot be explicitly null as a synonym for unassigned search.

`list_tasks` is introduced first. The other five read tools are added afterward. Shared querying/formatting is appropriate, but keep the exposed tools focused and separately described.

### Expected automated tests

| Test name | Expected assertions |
| --- | --- |
| `creates a named MCP token for the authenticated user` | Token belongs to actor; plaintext is shown once; persisted value is a hash; later renders do not show plaintext/hash. |
| `rejects blank and oversized MCP token names` | Useful field errors; no token created; guests cannot invoke token creation/revocation. |
| `revokes only the current users MCP tokens` | Own token is revoked; foreign token ID is rejected without deleting it; revoked token cannot authenticate. |
| `returns 401 for missing invalid and revoked MCP bearer tokens` | HTTP JSON-RPC request dataset returns 401 without tool data or a login-page redirect. |
| `authenticates a real Sanctum token at the MCP endpoint` | Real bearer token succeeds; `list_tasks` reflects the token owner's workspace and permitted data. |
| `returns 404 for unknown and inaccessible MCP workspace slugs` | Both endpoint cases hide workspace data after valid authentication. |
| `uses the MCP workspace URL instead of the browser session` | Conflicting session selection does not affect endpoint data; same multi-workspace token can use each permitted URL. |
| `lists only tasks from the selected workspace` | Known local summaries present; foreign and other-accessible-workspace summaries absent; exact output fields match contract. |
| `combines task filters without widening tenant scope` | Dataset/fixtures distinguish project/status/priority/assignee/search filters and their combinations; only conjunctive matches return. |
| `rejects invalid and foreign read filters` | Invalid enum/ID or foreign project/assignee errors are actionable; no foreign names or task data appear. |
| `paginates read results with scoped totals and bounded page sizes` | Default 25, requested page, maximum 100, invalid bounds, empty later page, stable ordering, and filtered tenant total are verified using datasets. |
| `lists projects with unfinished task counts` | Project summary fields match; counts exclude done tasks and other workspaces; project search filters correctly. |
| `lists only the authenticated users assigned tasks` | `list_my_tasks` never accepts an arbitrary actor; includes done tasks by default; status/priority filters work. |
| `returns task details with only the latest twenty comments` | Correct task/description/project/assignee/date; exactly latest 20 comments and correct total; author shape contains no private fields. |
| `rejects foreign and missing task IDs without disclosing existence` | `get_task` errors have equivalent public shape/message; inaccessible task/comments absent. |
| `searches task titles and descriptions within the selected workspace` | Title-only and description-only matches return; foreign matches do not; optional filters apply. |
| `rejects missing blank and oversized search queries` | Named invalid-query dataset produces actionable validation errors. |
| `treats wildcard characters as literal search text` | `%` and `_` do not unexpectedly return unrelated records; query remains tenant-scoped. |
| `lists only current workspace members with public fields` | Name search works; roles are workspace-specific; foreign users/emails/passwords/pivot metadata absent. |
| `allows owners members and viewers to use read tools` | Read-tool/role dataset succeeds for all three roles with selected-workspace data. |
| `rejects unsupported read arguments` | Extra keys fail; callers cannot inject an actor/workspace or arbitrary query options. |
| `advertises read tool names schemas and safety metadata` | Real `tools/list` exposes introduced tools with exact names, required arguments, enum/bound constraints, structured output schemas, and read-only metadata. |

### Commits and exit criteria

5. **C05 — `Add Sanctum access and workspace-scoped MCP endpoint`**: token management, authenticated server/context adapter, transport tests and connection README. The server can initialize even before tools are added.
6. **C06 — `Add list_tasks with structured responses`**: first tool and its tests. After relevant checks, create **`course/01-first-tool`**. Verify an actual client can list tasks through bearer authentication.
7. **C07 — `Add remaining read tools and member lookup`**: the five other tools and read tests. Create **`course/02-read-tools`** after checks.

Use Inspector and Claude Code, the primary documented client, for manual connection verification. Fetch current client configuration instructions when needed. Lack of access to a real client must be reported rather than represented as an automated-test failure or successful verification.

## Phase 4 — Agent mutations, destructive operations, and tool discovery

**Prerequisite:** phase 3. **Outcome:** semantic write tools that reuse the SaaS application rules, followed by role-specific discovery.

### Write tool contracts

All tools reuse the shared policies, validation, scoped record lookup, and mutations from phase 2. Each has a strict schema and concise structured success response. No generic action/payload argument, caller-supplied workspace, project transfer, or actor override is accepted.

| Tool | Inputs | Authorization and result |
| --- | --- | --- |
| `create_task` | Required `project_id`, `title`; optional nullable `description`, `assignee_id`, `due_date`; optional `priority` | Owner/member; selected-workspace project/assignee; default todo/medium; return summary plus description. |
| `update_task` | Required `task_id`; optional `title`, nullable `description`, `priority`, nullable `due_date`; at least one editable field | Owner/member; omitted fields preserved; null clears nullable fields; return summary plus description. Does not accept status/assignee/project changes. |
| `assign_task` | Required `task_id`; required nullable `assignee_id` | Owner/member; same-workspace assignee including viewer or null to unassign; return task summary. |
| `move_task` | Required `task_id`, `status` | Owner/member; enum values only; any of the three statuses may transition to any other; return task summary. |
| `add_comment` | Required `task_id`, `body` | Owner/member; author comes from actor; return `id`, `task_id`, `body`, `author: { id, name }`. |
| `delete_task` | Required `task_id` | Owner only; normal deletion including comments; return `deleted: true` and `task: { id, title }` captured before deletion. |

Use the shared length/date validation from phase 2. Return actionable messages, for example "The assignee must be a member of the current workspace" or "The status must be one of: todo, in_progress, done". Unexpected exceptions must not expose SQL, paths, or foreign model contents to the client.

Introduce appropriate annotations with each tool. All writes are not read-only; deletion is destructive. Metadata is advisory and does not replace policies or application/client confirmation. Do not promise an idempotency key or exactly-once execution. Creation/comments can duplicate on retry; later auditing means a repeated successful mutation can create another audit entry. Do not mark writes idempotent unless their complete observable behavior supports it.

### Role-dependent discovery

After the ordinary writes are complete, add `delete_task` and conditional registration:

- Owner: all six read tools and all six write tools.
- Member: all read tools and create/update/assign/move/comment; no delete.
- Viewer: all read tools; no writes.

Determine eligibility from the authenticated actor's role in the MCP URL's workspace. Use the installed package's `shouldRegister` mechanism and normal policy logic. Keep authorization inside underlying operations even when discovery/call dispatch also filters tools. A guessed hidden tool name must never mutate data.

### Expected automated tests

| Test name | Expected assertions |
| --- | --- |
| `creates a task in the selected project for owners and members` | Allowed-role dataset persists correct project/defaults/metadata and returns matching structured task; unrelated tasks unchanged. |
| `rejects invalid task creation without persisting a task` | Required fields, invalid priority/date, oversized text, foreign project/assignee and unknown-key datasets give useful errors; no task created. |
| `updates only submitted task metadata` | Exact edited fields persist; omitted fields, project, status, and assignee unchanged; response reflects persisted task. |
| `clears nullable task metadata with explicit null` | Description/date clear; null title/priority and empty metadata updates fail without changes. |
| `rejects status assignment and ownership changes through update_task` | Unsupported keys fail; status/assignee/project/workspace unchanged. |
| `assigns workspace members and unassigns tasks` | Valid member/viewer assignment and null unassignment persist; response shows resulting assignee. |
| `rejects foreign assignees without changing the task` | Workspace-specific error; previous assignee and metadata preserved. |
| `moves tasks between the allowed statuses` | Named transition dataset persists each allowed status including returning from done; invalid status fails unchanged. |
| `adds a comment as the authenticated user` | Correct task/body/actor persisted and returned; forged `user_id` fails without an extra comment. |
| `rejects invalid comments without creating a record` | Blank/oversized body errors are actionable; comment count unchanged. |
| `forbids viewer calls to every write operation` | Direct operation/tool-call dataset cannot change tasks/comments; works even independently of discovery hiding. |
| `rejects foreign record IDs in every applicable write tool` | Dataset covers foreign creation project and update/assign/move/comment/delete task; no changes in either workspace; equivalent missing-ID errors. |
| `rejects records in another accessible but inactive workspace` | Multi-membership actor still cannot mutate a task outside the endpoint-selected workspace. |
| `deletes tasks and comments only for owners` | Owner success response and database deletion; member/viewer application denial preserves both; other tasks untouched. |
| `advertises mutation schemas and destructive deletion metadata` | `tools/list` has exact write names, required/nullable/enum schemas, response schema and accurate read-only/destructive flags. |
| `discovers all tools for owners` | HTTP `tools/list` returns the exact twelve-tool set. |
| `hides delete_task from members` | Exact eleven-tool set; direct guessed HTTP deletion call fails and leaves records unchanged. |
| `hides every write tool from viewers` | Exact six-read-tool set; guessed write-name calls fail without mutations. |
| `uses the selected workspaces role for tool discovery` | Dana's Acme connection sees owner tools; Sandbox connection sees only read tools; no role cache carries across requests. |
| `keeps web and MCP mutation rules equivalent` | One representative shared-operation validation/authorization case exercised through each transport produces the same persisted outcome or denial. |

### Commits and exit criteria

8. **C08 — `Add create_task and update_task tools`**: metadata writes, schemas/annotations and tests.
9. **C09 — `Add assignment, status, and comment tools`**: remaining ordinary writes and tests. Create **`course/03-write-tools`** here, before introducing deletion and discovery filtering.
10. **C10 — `Add destructive deletion and role-based tool discovery`**: deletion, conditional registration, role/HTTP discovery tests. Create **`course/04-permissions`** after checks.

For manual demonstration, create a task, find Bob with member lookup, assign it, move it, and comment. Then connect as Charlie and demonstrate read-only discovery; connect as Bob and demonstrate deletion denial. Do not claim annotations guarantee a client confirmation dialog.

## Phase 5 — Project instructions resource and review prompt

**Prerequisite:** phase 4. **Outcome:** practical examples of all three MCP primitives without adding AI execution to the server.

### Resource

Register a parameterized project instructions resource using the installed package's resource-template API. URI: `project://{project}/instructions`, where `project` is the project ID.

Resolve the project through the selected endpoint workspace and authorize viewing. Owner/member/viewer can read. Return JSON content with exactly `project: { id, name, description }` and `instructions` (nullable). Declare an appropriate JSON MIME type and description. No task mutation occurs. Do not list every project as a separate hard-coded resource.

### Prompt

Register `project_review` with required `project_id`, validating and authorizing the selected-workspace project. Return the package's normal prompt messages, containing project identity and today's date in the configured application timezone, which stays `UTC`.

The prompt directs the client to read the project's instructions resource and use existing read tools, paginating as needed, to inspect unfinished tasks; identify overdue unfinished tasks (due date strictly before today), high-priority unfinished tasks, unassigned tasks, and likely blockers; summarize findings; and recommend the next three actions. Blockers are an inference from descriptions/comments, not a new task status or database field. Tell the client not to mutate data without a separate user request.

The server generates instructions; it does not call an LLM, execute tools on the client's behalf, or promise automatic resource consumption by every host. Fetching a prompt/resource must not change domain data.

### Expected automated tests

| Test name | Expected assertions |
| --- | --- |
| `discovers the project instructions resource template` | `resources/templates/list` exposes the expected URI template, name/description and MIME type using a real authenticated request. |
| `reads project instructions for every workspace role` | Role dataset returns exact permitted project context/instructions and JSON MIME type. |
| `returns nullable instructions for a project without guidance` | JSON is valid; instructions is null; no invented guidance. |
| `rejects foreign missing and malformed project resource URIs` | Safe resource/protocol errors, equivalent missing/foreign responses, no foreign descriptions/instructions. |
| `discovers project_review with a required project argument` | `prompts/list` advertises the exact name, description, and required argument. |
| `generates a review prompt for the selected workspace project` | Correct project/URI/tool names appear; controlled current date and overdue definition present; open/high-priority/unassigned/blocker/three-action guidance present. |
| `allows each workspace role to request a review prompt` | Owner/member/viewer dataset succeeds without needing mutation permission. |
| `rejects missing invalid and foreign review project arguments` | Useful errors, no inaccessible context and no domain changes. |
| `does not mutate data when reading resources or requesting prompts` | Task/comment/project state is unchanged; generated prompt does not execute write tools. |

### Commits and exit criteria

11. **C11 — `Expose project instructions as a resource template`**: resource/discovery/tenant tests.
12. **C12 — `Add the project_review prompt`**: prompt/tests, README instructions for invoking both primitives.

Create **`course/05-resources-prompts`** after checks. Verify via Inspector even if the selected AI client does not expose resources/prompts conveniently; document the client behavior actually observed.

## Phase 6 — Rate limiting, transactional audit trail, and core completion

**Prerequisite:** phase 5. **Outcome:** the complete core demo with practical operational protections and documented setup.

### Rate limiting

Use standard Laravel named rate limiting on MCP POST requests, with a default of 60 requests per minute per authenticated user across all workspace endpoints. Return HTTP 429 with retry information when exhausted. Put authentication before the per-user limiter and apply it to all JSON-RPC methods, including initialization/discovery, not only tool writes. Read the limit from one config value backed by an environment variable (default 60), documented in the README, so a demo can lower it; tests set it explicitly. Do not introduce a distributed cache dependency for the local demo.

### Audit trail

Add `mcp_audit_logs`: `id`, `user_id`, `workspace_id`, `tool`, nullable `entity_type`, nullable `entity_id`, `summary`, timestamps. Use appropriate foreign keys for actor/workspace; entity references are descriptive and must not cascade away when a task/comment is deleted. Use stable entity labels `task` and `comment`.

Log every successful MCP invocation of create/update/assign/move/comment/delete. Store actor, selected workspace, exact tool name, resulting entity reference (comment for add_comment; task for other operations), and a concise human-readable summary. Capture deleted task identity before deletion. Do not log bearer tokens, entire request payloads, or full descriptions/comment bodies. Read operations, resources/prompts, and web mutations do not produce MCP audit entries.

Wrap the domain mutation and audit insert in the same database transaction. An audit persistence failure must roll back the mutation, including deletion and cascaded comments. Keep MCP-specific logging outside ordinary web operations; do not build a generic auditing framework. A repeated successful invocation can create another audit entry; no exactly-once/retry-key feature is required.

Add an owner-only Livewire page with paginated recent audit entries for the selected workspace, ordered by timestamp descending then ID descending. Show tool, actor name, summary, entity reference, and time. Do not require deleted entities to resolve in order to display their history. Members/viewers get 403 when they attempt the page directly.

### Documentation and final verification

README must explain installed requirements, dependency installation, environment configuration, migration/seed, frontend build/dev commands, local startup, demo credentials, roles, empty-state behavior, domain hierarchy, workspace switching, Sanctum token creation/revocation, workspace MCP URLs, Inspector and verified client setup, all twelve tools and their pagination, resource URI, prompt usage, destructive operations, rate limit, audit page, and test/static-analysis commands.

Explain the distinction between identity, membership, and policies. Document token revocation and bounded outputs. Do not imply project instructions are enforced validation rules or that metadata guarantees a host confirmation. Clearly identify local-demo credentials and avoid committing generated tokens/keys.

Run accumulated tests, static analysis, formatting, and the frontend build. On a disposable local/test database, verify fresh migration and demo seeding. Do not reset the user's active database without authorization. Manually verify an authenticated real-client read/create/assign/move/comment/delete workflow, viewer restrictions, cross-tenant denial, resource/prompt inspection, audit visibility, and revocation. Record verified versus unavailable client checks honestly in the completion report/README.

### Expected automated tests

| Test name | Expected assertions |
| --- | --- |
| `returns 429 after the per-user MCP request limit is exhausted` | Controlled request burst reaches 60 allowed requests, then 429 with retry information; excess write request changes no records/audits. |
| `shares a users rate limit across workspace connections` | Requests to two permitted workspace URLs consume one actor's budget. |
| `does not share rate limits between different users` | Another user remains able to make a valid request after the first exhausts their limit. |
| `resumes MCP access after the rate limit window` | Time advances using test helpers; valid request succeeds again without sleeping. |
| `logs exactly one audit entry for each successful MCP mutation` | Six-tool dataset asserts actor/workspace/tool/entity/summary and exact count alongside the intended domain change. |
| `preserves task deletion history after deleting its comments` | Task/comments removed; audit keeps deleted task ID/title summary and remains renderable. |
| `does not audit reads resources prompts or web mutations as MCP writes` | Representative calls leave audit count unchanged. |
| `does not create success audits for failed MCP mutations` | Validation/permission/foreign-record/unknown-tool cases leave both domain and audit state unchanged. |
| `rolls back domain mutations when audit persistence fails` | Narrow fault injection at audit persistence causes create/update/comment/delete to roll back; no partial domain change or audit row. Do not mock tenant queries or replace the whole mutation path. |
| `shows only current workspace audit entries to owners` | Correct rows/actor/tool/time appear in descending order with pagination; foreign and other accessible workspace entries absent. |
| `returns 403 for members and viewers requesting the audit page` | Direct-route dataset is forbidden and displays no audit data. |
| `keeps secrets and full content out of audit records` | Audit summary/fields contain no token or complete submitted long description/comment payload. |
| `completes an authenticated MCP task workflow with an audit trail` | HTTP sequence creates a task, assigns a known member, changes status, comments, reads it, deletes it; correct resulting state/audit sequence without an external AI client. |

### Commits and exit criteria

13. **C13 — `Add MCP endpoint rate limiting`**: limiter and HTTP regression tests.
14. **C14 — `Add transactional MCP audit logging and owner audit page`**: schema, transactional write integration, audit UI/tests; rerun all mutation tests.
15. **C15 — `Finalize setup documentation and verify demo workflows`**: complete README, focused final integration coverage, any necessary verification fixes.

Create **`course/06-production-basics`** after core-completion checks. Phases 1–6 now satisfy the core application's acceptance criteria. Deployment and hosting remain separate authorization/scope decisions.

## Phase 7 — Passport OAuth course extension

**Prerequisite:** phase 6 and scheduling/authorization to implement this extension. **Outcome:** a real browser authorization and PKCE flow for compatible MCP clients, using the same workspace permissions and tools.

### Implementation scope

- Install/configure Passport and required authenticatable contracts/guard, keys, migrations, authorization views, and official Laravel MCP OAuth discovery/client-registration routes using version-appropriate documentation.
- Migrate the teaching MCP endpoint from Sanctum bearer authentication to Passport's documented OAuth guard. Preserve the workspace URL and resolver contract, tool schemas, policies, validation, auditing, and rate limits. Do not maintain two authentication modes on one endpoint or rewrite the tools.
- Leave phase 6's Sanctum behavior reproducible at its tag. In the OAuth final application remove Sanctum entirely: the MCP access page and its route, the API-token trait on the user model, the package, and its token table through a new migration. Keep a regression test that former Sanctum tokens are rejected. Do not remove existing starter-kit login/security capabilities.
- Update superseded Sanctum endpoint/token-UI tests to the final OAuth contract, preserving their earlier implementation at `course/06-production-basics`. The final suite must not simultaneously require Sanctum-token acceptance and rejection at the same endpoint. Keep shared domain/tenant/policy tests and retain regression coverage for retired UI and rejected legacy tokens; do not delete test files without authorization.
- Implement the normal unauthenticated challenge/discovery, supported client registration, browser login/consent, authorization-code exchange with PKCE, token use, and revocation. Never put generated OAuth keys, secrets, access tokens, or refresh tokens into Git.
- Use official Laravel MCP Passport helpers and the advertised `mcp:use` scope. Do not map workspace roles to OAuth scopes. The same actor can be an owner in one workspace and viewer in another.
- Consent must be deliberate. Do not auto-approve clients or disable PKCE to make a demonstration work. Support only redirect schemes allowed by the documented configuration; do not weaken validation.
- Update README for key/setup requirements, current authentication mode, the OAuth connection sequence, compatible-client configuration actually verified, and how to check out the earlier Sanctum lesson.

### Expected automated tests

Use in-process HTTP integration tests with Passport test keys/clients where needed; no external OAuth service or real browser is required for these acceptance contracts. Guard impersonation helpers alone are insufficient to verify discovery, PKCE, or token rejection.

| Test name | Expected assertions |
| --- | --- |
| `advertises OAuth discovery when an MCP request is unauthenticated` | HTTP 401 carries the documented OAuth challenge; referenced metadata endpoints resolve with expected issuer/resource/auth/token information. |
| `registers an allowed public MCP OAuth client` | Official registration endpoint accepts allowed redirect configuration and public-client authentication mode; rejects invalid redirects/configuration according to installed helpers. |
| `requires login and consent before issuing an authorization code` | Guest follows login flow; authenticated user sees consent; approval permits code issuance; denial grants no access. |
| `exchanges an authorization code using a valid PKCE verifier` | Real code/token flow yields a usable access token; bearer request returns the approving user's permitted workspace data. |
| `rejects invalid PKCE verifiers and reused authorization codes` | Token endpoint rejects both cases; no usable replacement grant. |
| `returns 401 for invalid expired and revoked OAuth access tokens` | Real-token dataset fails at MCP boundary without returning tenant data. |
| `rejects legacy Sanctum tokens at the OAuth MCP endpoint` | Former authentication tokens cannot bypass the new guard; retired token UI is not offered as a working MCP connection method. |
| `keeps workspace isolation and role discovery under OAuth` | Owner/member/viewer discovery sets remain exact; foreign workspace gets 404; Dana's role changes with endpoint workspace. |
| `keeps mutation authorization and auditing under OAuth` | Allowed OAuth write persists and audits correct actor; viewer/member deletion denial changes nothing. |
| `keeps the per-user MCP rate limit under OAuth` | Different OAuth clients/tokens for the same user share the limiter; a different user has a separate budget. |
| `preserves starter kit browser login and account security` | Existing authentication/settings tests still pass; Passport integration does not change ordinary browser identity. |

### Commit and exit criteria

16. **C16 — `Add Passport OAuth connection flow`**: OAuth integration, retiring the incompatible token UI, tests, current setup/connection README. Split into smaller coherent commits if necessary; tag only the complete working flow.

Run the complete suite, existing static analysis, formatting/build checks, and a real compatible-client authorization flow where available. Recheck all previously established tenant/role/write/resource/prompt/audit contracts under the final guard. Create **`course/07-oauth`** only when the implementation and automated checks pass; state separately whether real-client verification was available.

## Suggested video lessons and required Git tags

Implementation phases group engineering work; lesson tags identify teaching states. The tag requirement does not add phases. It creates two checkpoints inside phase 3 and two inside phase 4 so first-tool/read-tool and write-tool/permission lessons have clean diffs.

| Lesson | Suggested title and demonstration | Start → end checkpoint | Tag placement |
| --- | --- | --- | --- |
| **00** | **Meet TeamBoard: the Laravel SaaS we will make agent-ready.** Show the normal UI, workspace switcher, Alice/Bob/Charlie roles, Dana's different memberships, and Globex isolation. A short preview of the finished MCP integration can open the course. | Existing starter kit → SaaS baseline | `course/00-teamboard-baseline` after C04 / phase 2 |
| **01** | **Your first authenticated Laravel MCP tool.** Explain web transport, explicit workspace context, token authentication, schema/structured output, and ask a client to list tasks. | `00` → first tool | `course/01-first-tool` after C06 / phase 3 first checkpoint |
| **02** | **Build useful read tools for agents.** Filtering, search, personal tasks, member discovery, task detail, pagination, bounded comments, and useful errors. | `01` → read surface | `course/02-read-tools` after C07 / phase 3 completion |
| **03** | **Let the agent create and update work.** Create a task, find Bob, assign it, move it, and add a comment; explain shared application rules and partial updates. | `02` → ordinary writes | `course/03-write-tools` after C09 / phase 4 first checkpoint |
| **04** | **Permissions, tenant isolation, and dangerous tools.** Add owner-only deletion and annotations; show owner/member/viewer discovery, guessed-ID/hidden-tool denials, and the regression tests. | `03` → full permissions surface | `course/04-permissions` after C10 / phase 4 completion |
| **05** | **Tools, resources, and prompts in practice.** Read project instructions and invoke a reusable review prompt; explain host behavior and why the server itself does not run the review. | `04` → context primitives | `course/05-resources-prompts` after C12 / phase 5 |
| **06** | **Production basics: rate limits, audit trails, and verification.** Show an agent mutation in the owner audit page, transaction guarantees, middleware tests, token revocation, and reproducible setup. This lesson does not require deployment. | `05` → complete core demo | `course/06-production-basics` after C15 / phase 6 |
| **07** | **Connect through OAuth with Laravel Passport.** Replace the token-copying setup with login/consent/PKCE; keep workspace policies and existing tools intact. | `06` → OAuth extension | `course/07-oauth` after C16 / phase 7 |

### Instructions to the implementer for commits and tags

1. Keep a linear history on the working branch. Commit each listed boundary with its implementation and tests; do not squash the whole demo into one commit or create a branch per lesson. Respect existing unrelated user changes and commit only work belonging to the phase.
2. Keep each commit runnable. If a boundary needs more than one commit, preserve its teaching intent and place the tag on its final verified commit. Tests are present from the first introduction of each behavior, even if explained in a later video.
3. Update README instructions when a checkpoint introduces a new setup requirement. Do not make earlier lesson tags depend on future migrations, utilities, or setup steps that exist only at the final tag.
4. At each checkpoint, ensure the intended commit is committed, relevant tests and static analysis have passed, and no unfinished application changes remain. Create an **annotated local Git tag** using the exact name above and a short description of the verified checkpoint.
5. Before creating a tag, inspect whether it exists. If it already points at the intended verified commit, preserve it. If it points elsewhere, report the conflict; never force-move or delete a course tag without explicit authorization.
6. Do not place all tags on the final commit. In particular, `01` precedes the remaining read tools, `03` precedes destructive deletion/conditional discovery, and `06` preserves the working Sanctum version before OAuth migration.
7. Do not push commits or tags without authorization. Report created commit IDs, checkpoint tags, test results, and any unverified manual client checks at the end of each implementation session.
8. Reproduce lessons by checking out tags in a separate worktree or clean checkout with an isolated local database. Resolve the tag's own dependencies, migrate/seed according to that tag's README, and avoid running older code against the final lesson's database accidentally. Never overwrite the user's active working tree or reset their database merely to rehearse a video.
