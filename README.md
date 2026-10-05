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

## Testing

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
composer types:check
```
