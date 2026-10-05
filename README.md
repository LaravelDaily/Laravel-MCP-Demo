# TeamBoard

TeamBoard is a small multi-tenant Laravel SaaS with workspaces, projects, tasks, and comments. It is the application used to demonstrate making an existing Laravel SaaS agent-ready through the official Laravel MCP package.

## Requirements

- PHP 8.4 with the [Laravel Herd](https://herd.laravel.com/) default extensions (or an equivalent local PHP setup)
- Composer
- Node.js and npm

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
```

Then serve the application with Herd, or run:

```bash
composer run dev
```

The database defaults to `database/database.sqlite`. Seeding creates the demo workspaces and accounts below; run `php artisan migrate:fresh --seed` on a database you are willing to reset.

## Demo accounts

All demo data is deterministic and intended for local use only. Every demo account uses the password `password`.

| Account | Email | Memberships |
| --- | --- | --- |
| Alice | `alice@example.com` | Acme **owner** |
| Bob | `bob@example.com` | Acme **member** |
| Charlie | `charlie@example.com` | Acme **viewer** |
| Dana | `dana@example.com` | Acme **owner**, Sandbox **viewer** |
| Eve | `eve@example.com` | Globex **owner** |

The Acme workspace contains the *Website Redesign* and *Billing Improvements* projects with a mix of task statuses, priorities, assignees, due dates, and comments. Dana demonstrates workspace switching and holding different roles in different workspaces. Nobody from Acme belongs to Globex, so cross-tenant mistakes are easy to spot.

## Roles

- **Owner** — manages projects and tasks, comments, and deletes tasks
- **Member** — creates and edits tasks, assigns work, changes status, comments
- **Viewer** — read-only access, but may be assigned tasks

## Using the app

The domain hierarchy is workspace → project → task → comment. Log in as Alice (`alice@example.com`) to explore:

- The **workspace switcher** in the sidebar lists only your workspaces (with your role in each) and stores the selection per browser session. Switching always returns to the dashboard, so a project or task page cannot keep showing the previous workspace's records.
- The **dashboard** summarizes the selected workspace: project count, open tasks (`todo` + `in_progress`), and your own unfinished assignments. Users without any workspace membership see an empty state instead of someone else's data.
- **Projects** lists the selected workspace's projects. Only owners can create, edit, and delete projects (deleting a project removes its tasks and comments). The project page shows the description, the AI instructions, and the project's tasks.
- A **task** page shows its metadata, comments, and — for owners and members — inline controls to change status and assignment, an edit form for title/description/priority/due date, and a comment box. Deleting a task (owners only) also deletes its comments.

Every page and mutation is scoped to the selected workspace: foreign records return 404, and role denials within an accessible workspace return 403.

## Testing

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
composer types:check
```
