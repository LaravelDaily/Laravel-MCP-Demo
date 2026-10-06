# Lesson 00 — Meet TeamBoard: the Laravel SaaS we will make agent-ready

| | |
| --- | --- |
| Starts at | Laravel Livewire starter kit (with `laravel/mcp` installed but unused) |
| Ends at | `course/00-teamboard-baseline` |
| Length goal | 10–15 minutes |

## Goal

Show the ordinary multi-tenant SaaS that the rest of the course connects to AI clients. Students should leave knowing the domain (workspace → project → task → comment), the three roles, and the two rules every later lesson relies on: **records are always looked up through the selected workspace**, and **policies decide what a role may do**.

## Commands

Before recording (from the repository root):

```bash
git switch --detach course/00-teamboard-baseline
composer install
cp .env.example .env            # first time only, then php artisan key:generate
php artisan migrate:fresh --seed
npm install && npm run build
herd secure teamboard           # first time only
```

Commands used to build this lesson's code (mention, don't run): `php artisan make:model <Name> -mf` for Workspace, Project, Task and Comment, `php artisan make:policy`, `php artisan make:livewire <Name> --class` for the pages, and `php artisan make:seeder TeamBoardDemoSeeder`. Laravel MCP was installed before this lesson (`composer require laravel/mcp`, which also added `routes/ai.php`), but nothing uses it yet.

## Demo script

1. Log in at `https://teamboard.test/login` as **Alice** (`alice@example.com` / `password`).
   - The dashboard shows the Acme workspace: project count, open tasks (`todo` plus `in_progress`), and Alice's unfinished assignments.
2. Open **Projects → Website Redesign**: description, the **AI instructions** field, and the task list.
   - Point out the instructions ("acceptance criteria are required…"). Lesson 05 exposes them to agents.
3. Open **Investigate failed Stripe webhook**: status, priority, assignee, an overdue due date, and comments.
   - As the owner, Alice can change the status, edit and delete.
4. Log out and log in as **Bob** (member): he can edit tasks and comment, but the project create/edit/delete buttons and the task Delete button are gone.
5. Log in as **Charlie** (viewer): everything is read-only.
6. Log in as **Dana**: use the **workspace switcher** in the sidebar to move between Acme (owner) and Sandbox (viewer).
   - Switching always returns to the dashboard, and the visible controls change with her role.
7. Log in as **Eve**: Globex only, with completely different projects ("Globex Payroll Platform").
   - Try opening `https://teamboard.test/projects/1` (Acme's project) as Eve to get **404**, not 403: other tenants' records look like they don't exist.
8. Optional teaser: a 20-second clip from lesson 04/05 of Claude Code working with these same tasks.

## Code walkthrough

1. **Domain**: `app/Models` (`Workspace`, `Project`, `Task`, `Comment`, and `Membership`, the pivot on `workspace_user` that casts `role`).
   - Enums in `app/Enums`: `WorkspaceRole` with `canContribute()`, `TaskStatus` with `openValues()`, and `TaskPriority`.
   - A task is "completed" only when its status is `done`.
2. **Workspace context**: `app/Workspaces/WorkspaceContext.php` is the single source of "which workspace are we in".
   - `current()` uses the session selection, falls back to the user's first membership, and clears stale selections.
   - `projects()`, `tasks()`, `project($id)` and `task($id)` are always scoped to that workspace, and foreign IDs raise not-found.
   - `switchTo()` accepts only the user's own workspaces.
   - `setExplicitWorkspace()` is the **seam for MCP**: a later request adapter can set the workspace from a URL, and the value lives only on the current request. Mention it now; lesson 01 uses it.
3. **Policies**: `app/Policies`. The role matrix is `WorkspaceRole::canContribute()` for owner/member, and owner-only for project management and task deletion.
   - Authentication, membership and policies are three separate layers.
4. **Shared actions**: `app/Actions/Tasks` (`CreateTask`, `UpdateTaskDetails`, `AssignTask`, `ChangeTaskStatus`, `AddComment`, `DeleteTask`) with `app/Concerns/TaskValidationRules.php`.
   - Each action re-checks the workspace, authorizes, validates and only then writes.
   - The Livewire pages call them, and lesson 03's MCP write tools call the same ones. That's the reason they exist.
5. **Livewire pages**: `app/Livewire` (Dashboard, Projects, Tasks, WorkspaceSwitcher).
   - Record IDs are `#[Locked]`, every action re-resolves the record through the context, and lists use `WithPagination`.
6. **Demo data**: `database/seeders/TeamBoardDemoSeeder.php`.
   - It's deterministic, its due dates are relative to the seed time, and Globex has recognizable names so cross-tenant leaks are obvious.

## Tests to show

```bash
php artisan test --compact tests/Feature/WorkspaceContextTest.php tests/Feature/AuthorizationTest.php
```

- `AuthorizationTest`: `enforces the workspace role permission matrix`. One dataset covers every ability × role, including a non-member who owns another workspace.
- `WorkspaceContextTest`:
  - `scopes records to the selected workspace even with another valid membership`
  - `does not carry workspace context into another request`
- `ProjectsTest`: `returns 404 for foreign project and task routes`.
- `TasksTest`: `rechecks permission when membership changes after a component mounts`. Hidden buttons are not security.

## Gotchas

- The demo data is local only. Every account uses `password`.
- `migrate:fresh --seed` resets your local database. Only run it before recording.
- The app timezone is UTC, so "overdue" flips at UTC midnight.
