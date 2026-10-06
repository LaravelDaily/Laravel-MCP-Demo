# Lesson 00 — Meet TeamBoard: the Laravel SaaS we will make agent-ready

| | |
| --- | --- |
| Starts at | Laravel Livewire starter kit (with `laravel/mcp` installed but unused) |
| Ends at | `course/00-teamboard-baseline` |
| Length goal | 3–4 minutes |

## Goal

A short hook, not a code walkthrough:

1. **About one minute:** a quick tour of an ordinary multi-tenant SaaS.
2. **The question:** "how do we let an AI agent safely work with this data?"
3. **The payoff:** one Claude Code prompt against the finished app, so viewers see where the course ends up.

The internals (workspace context, policies, shared actions) are explained later, in the lesson where MCP first uses each one.

## Recording setup

Record this lesson in two parts from two tags, so the tour doesn't show MCP features before they're introduced.

**Part A, the tour, at the baseline:**

```bash
git switch --detach course/00-teamboard-baseline
composer install
php artisan migrate:fresh --seed
npm install && npm run build
php artisan config:clear
```

**Part B, the demo, on the finished app:**

```bash
git switch --detach course/06-production-basics
composer install
php artisan migrate:fresh --seed   # fresh data, so the demo story is the same every take
npm run build
php artisan config:clear
```

Then, as **Alice**, create a token under **Settings → MCP access**, and connect it under a short, clean name:

```bash
read -rs 'TEAMBOARD_TOKEN?Paste your MCP token: '; echo
claude mcp add --transport http --scope local teamboard \
  https://teamboard.test/mcp/workspaces/acme \
  --header "Authorization: Bearer $TEAMBOARD_TOKEN"
unset TEAMBOARD_TOKEN
export NODE_EXTRA_CA_CERTS="$HOME/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem"
claude --allowedTools "mcp__teamboard__list_projects" "mcp__teamboard__list_tasks" \
  "mcp__teamboard__search_tasks" "mcp__teamboard__get_task" "mcp__teamboard__list_workspace_members" \
  "mcp__teamboard__create_task" "mcp__teamboard__assign_task" "mcp__teamboard__add_comment"
```

This pre-approves only the tools the demo needs, so it runs without approval prompts. `delete_task` and the other writes still ask first. Don't use the shortcut `--allowedTools "mcp__teamboard"`: Alice is an owner, so it would also pre-approve deletion. In later lessons, keep the approval prompts on camera, because they're part of the safety story.

Do a full rehearsal take first, then run `php artisan migrate:fresh --seed` again before the real take, so the data is in the same state.

## Script

### Part A: the tour (about 1 minute, at the baseline)

As **Alice** at `https://teamboard.test`:

1. **Dashboard:** "This is TeamBoard, a typical team SaaS: workspaces, projects, tasks, comments."
2. **Projects → Billing Improvements → Investigate failed Stripe webhook:** an overdue, high-priority task with a comment thread about failed webhooks and duplicate charges.
3. **The workspace switcher:** "Users belong to workspaces with roles: owner, member or viewer. This company's data must never leak to another company."
4. Back to the dashboard.

Then say the premise over the dashboard:

> "All of this data lives inside our Laravel app. What if your users could just *ask* an AI agent about their work, and let it update tasks for them, without giving it database access, without leaking other customers' data, and without letting a viewer change anything? That's what we'll build in this course, with the official Laravel MCP package. Here's what the end result looks like."

### Part B: the payoff (about 1–2 minutes, finished app)

Paste this prompt into Claude Code:

> I'm Alice, and I just got back from vacation. What's on fire in our team's TeamBoard workspace? Find anything overdue or high priority, read the comments on the most urgent overdue task to understand what's really going on, then create a follow-up task in the same project for the most important next step, assign it to Bob, and leave a comment on the original task that points to the new one.

What should happen (the exact wording will vary):

- **Reading:** Claude calls `list_tasks` (and `list_projects` or `search_tasks`), and spots **Investigate failed Stripe webhook**: overdue, high priority, still in progress.
- **Understanding:** it calls `get_task` and reads the comments:
  - the mismatches started after a key rotation;
  - Bob offered to pull the failed delivery logs;
  - **support reports customers asking about duplicate charges**.
- **Acting:**
  - `list_workspace_members` to find Bob;
  - `create_task` in **Billing Improvements** with something like "Audit and refund duplicate charges from failed webhooks";
  - `assign_task` to Bob;
  - `add_comment` on the original task, referring to the new task's ID.
- **Summary:** Claude ends with a short briefing and what it changed.

Then switch to the browser, still logged in as Alice:

1. **Refresh Billing Improvements:** the new task is there and assigned to Bob.
2. **Open the Stripe webhook task:** the new comment is at the bottom, written by **Alice**, because the agent acts as the token's user.
3. **Sidebar → MCP audit log:** every change the agent made, newest first.

Close with:

> "The agent only saw Acme's data, acted with Alice's permissions, and left an audit trail. In lesson 01 we start from this plain app and connect our first tool."

### Optional 10-second extra

Connect a second server with **Charlie's** token (a viewer) and ask the same prompt.

- Expected: Charlie's connection has only read tools, so Claude can produce the briefing but can't create or assign anything.
- Use this only if the take stays short. Lesson 04 covers it properly.

## If the demo goes off-script

- **Claude picks a different "most urgent" task:** that's fine, as long as it reads comments and acts. Re-seed and try another take.
- **It asks before acting:** answer "yes, go ahead".
- **401 / connection errors:** the token was revoked or you re-seeded *after* creating it. Re-seeding wipes tokens, so create a new one after every `migrate:fresh --seed`.
- **Relative due dates:** "overdue" depends on today's date, which is why you re-seed right before recording.

## After recording

```bash
claude mcp remove teamboard -s local
git switch main && composer install && php artisan migrate && npm run build
```

Also revoke Alice's token in Settings if you didn't re-seed.
