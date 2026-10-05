# TeamBoard MCP Demo — Implementation Specification

## Purpose

Build a small but production-shaped multi-tenant Laravel SaaS application called **TeamBoard**.

The project exists primarily as a practical demonstration of how an existing Laravel SaaS can expose its functionality to AI agents through the official Laravel MCP package.

The web application itself should stay intentionally simple. Do not overbuild project-management features. The important parts are:

- multi-tenancy;
- team membership and roles;
- authorization;
- a small Projects + Tasks domain;
- a clean MCP server;
- read and write MCP tools;
- safe tenant isolation;
- authentication-ready architecture;
- realistic validation and error handling;
- tests proving that MCP access respects the same business rules as the web application.

The finished application should be suitable for later teaching as a step-by-step Laravel MCP course.

---

# 1. Starting Point

Assume the repository already contains:

- Laravel installed;
- the Laravel **Livewire starter kit** installed and working;
- user registration/login from the starter kit;
- the official **Laravel MCP** package installed;
- a configured database;
- Pest available for tests.

Do not reinstall or replace the starter kit.

Do not introduce Filament, Inertia, Vue, React, or another admin panel.

Use the existing Livewire stack.

---

# 2. Technical Principles

Follow normal Laravel conventions.

Prefer:

- Eloquent relationships;
- Form Requests or equivalent Laravel validation patterns;
- Policies and Gates for authorization;
- route model binding;
- enums where they materially improve clarity;
- service/action classes only when they genuinely reduce duplication;
- Pest tests;
- factories and seeders;
- small, focused MCP tools.

Avoid:

- unnecessary repositories;
- unnecessary DTO layers;
- generic "manager" or "service" abstractions;
- event-driven architecture unless required;
- premature caching;
- excessive JavaScript;
- complex frontend state;
- generic CRUD MCP tools accepting arbitrary payloads.

The codebase should be easy to teach.

Favor explicit Laravel code over abstraction-heavy architecture.

---

# 3. Domain Model

The core hierarchy is:

```text
User
 └── belongs to many Workspaces through Memberships

Workspace
 ├── has many Members
 ├── has many Projects
 └── has many Tasks through Projects

Project
 └── has many Tasks

Task
 ├── belongs to Project
 ├── may be assigned to a User
 └── has many Comments

Comment
 ├── belongs to Task
 └── belongs to User
```

---

# 4. Database Schema

## users

Use the existing starter-kit users table.

No major changes are required.

---

## workspaces

Fields:

- `id`
- `name`
- `slug`
- timestamps

Requirements:

- `slug` must be unique;
- use a factory;
- use route model binding where convenient.

---

## workspace_user

Pivot table representing workspace membership.

Fields:

- `workspace_id`
- `user_id`
- `role`
- timestamps

Use a role enum with exactly:

```text
owner
member
viewer
```

Business rules:

- `owner`
  - full access;
  - can manage projects;
  - can create/update/complete/delete tasks;
  - can comment;
- `member`
  - can view workspace data;
  - can create tasks;
  - can update tasks;
  - can assign tasks;
  - can complete tasks;
  - can comment;
  - cannot delete projects;
  - cannot delete tasks;
- `viewer`
  - read-only;
  - cannot create, update, assign, complete, comment, or delete.

A user may belong to multiple workspaces.

---

## projects

Fields:

- `id`
- `workspace_id`
- `name`
- `description` nullable
- `instructions` nullable text
- timestamps

`instructions` contains optional project-specific guidance intended to later be exposed to AI agents through an MCP Resource.

Example:

```text
When creating tasks for this project, always include acceptance criteria.
Bug reports should mention reproduction steps.
```

---

## tasks

Fields:

- `id`
- `project_id`
- `assignee_id` nullable foreign key to users
- `title`
- `description` nullable text
- `status`
- `priority`
- `due_date` nullable date
- timestamps

Use enums.

### Task status

Exactly:

```text
todo
in_progress
done
```

### Task priority

Exactly:

```text
low
medium
high
```

Default:

```text
medium
```

Business rules:

- assignee must belong to the same workspace as the project;
- a task can only be accessed through the workspace that owns its project;
- completed tasks use `status = done`;
- do not add a separate completed boolean.

---

## comments

Fields:

- `id`
- `task_id`
- `user_id`
- `body`
- timestamps

Business rules:

- only workspace owners and members may comment;
- viewers may read comments but cannot create them.

---

# 5. Current Workspace

The application must have the concept of a **current workspace**.

Keep this implementation simple.

Users who belong to at least one workspace should be able to select the active workspace from the UI.

Persist the selected workspace in the session.

If no workspace has been selected:

- default to the first workspace available to the user;
- never allow a user to select a workspace they do not belong to.

Create a small reusable service or helper responsible for resolving the current workspace.

Do not spread session-resolution logic throughout controllers/components/tools.

Example responsibility:

```php
$currentWorkspace = $workspaceContext->current();
```

The exact class name may differ, but the concept should be centralized.

---

# 6. Web UI

The UI exists only to make the SaaS understandable and testable.

Keep styling aligned with the existing Livewire starter kit.

Do not spend significant effort on design.

Required pages:

## Dashboard

Show:

- current workspace;
- number of projects;
- open task count;
- tasks assigned to the logged-in user.

---

## Workspace switcher

Allow switching among workspaces the authenticated user belongs to.

---

## Projects index

Show projects in the current workspace.

Allow:

- owner: create/edit/delete projects;
- member: view projects;
- viewer: view projects.

Project deletion should require owner permission.

---

## Project detail

Show:

- project name;
- description;
- project AI instructions;
- tasks.

---

## Task creation/editing

Owners and members can:

- create task;
- edit title;
- edit description;
- change status;
- change priority;
- change due date;
- assign to a workspace member.

Viewers cannot edit.

---

## Task detail

Show:

- title;
- description;
- project;
- status;
- priority;
- assignee;
- due date;
- comments.

Owners and members may comment.

Viewers may only read.

---

# 7. Authorization

Authorization is one of the most important parts of this project.

Create Laravel Policies.

At minimum:

- `WorkspacePolicy`
- `ProjectPolicy`
- `TaskPolicy`
- `CommentPolicy`

Do not rely on UI hiding alone.

All write operations must be protected at the application layer.

Every MCP operation must use the same authorization rules as the web UI.

Important:

**Authentication answers who the user is. Authorization answers what that user can do. Tenant scoping answers which data that user is allowed to access.**

These three concerns should remain distinct.

---

# 8. Tenant Isolation

This requirement is critical.

A user must never be able to retrieve or modify another workspace's data simply by guessing an ID.

For example:

```text
User belongs to Workspace A.

Task #50 belongs to Workspace B.

User requests Task #50 through:
- web UI;
- route;
- MCP tool;
- nested relation;
- direct input ID.

Result: access denied or task not found.
```

Choose a consistent approach.

Prefer querying resources through workspaces the authenticated user belongs to rather than fetching a global model and checking afterward.

Bad:

```php
$task = Task::findOrFail($id);
```

Preferred conceptually:

```php
$task = Task::query()
    ->whereHas('project.workspace.members', ...)
    ->findOrFail($id);
```

or equivalent scoped logic.

Centralize reusable tenant-scoped queries where that improves clarity.

Tests must explicitly cover ID enumeration / cross-tenant access.

---

# 9. Seed Data

Create useful demo data.

Seed at least:

## Workspace: Acme

Users:

- Alice — owner
- Bob — member
- Charlie — viewer

Projects:

### Website Redesign

Example tasks:

- Redesign pricing page
- Fix mobile navigation
- Prepare launch checklist

### Billing Improvements

Example tasks:

- Investigate failed Stripe webhook
- Add invoice download
- Improve subscription cancellation flow

---

## Workspace: Globex

Create at least one separate user and a few tasks.

The Acme users must not be able to access Globex data.

This workspace exists primarily to test tenant isolation.

Use predictable seeded credentials suitable for local development.

Document them in the README.

---

# 10. MCP Server

Create a web-accessible MCP server for TeamBoard.

Use the official Laravel MCP package.

The MCP server should operate on behalf of the authenticated user.

Do not create a separate MCP-specific permission model.

MCP should reuse:

- Laravel authentication;
- current-user identity;
- workspace membership;
- Laravel policies;
- tenant-scoped queries.

The MCP server should be structured so that authentication middleware can later be swapped or extended without rewriting the tools.

---

# 11. MCP Tool Design Principles

Avoid one generic CRUD tool.

Do NOT build:

```text
manage_task(action, payload)
```

Prefer small semantic tools.

Every tool must have:

- clear name;
- clear description;
- strict input schema;
- Laravel validation;
- useful validation messages;
- tenant-aware querying;
- authorization;
- concise output;
- tests.

Do not expose unnecessary database fields.

Responses should be optimized for an AI agent, not for serializing complete Eloquent models.

Prefer stable structured data.

---

# 12. Required MCP Read Tools

Implement the following.

## `list_projects`

Purpose:

List projects from the current workspace.

Optional inputs:

- search string

Output per project:

- id;
- name;
- description;
- open task count.

Must not return projects from any other workspace.

---

## `list_tasks`

Purpose:

List tasks from the current workspace.

Optional filters:

- project ID;
- status;
- priority;
- assignee ID;
- search text.

Output should be concise.

Per task include:

- id;
- title;
- project id;
- project name;
- status;
- priority;
- assignee;
- due date.

Pagination or a sensible result limit should be implemented.

Avoid returning hundreds of records.

---

## `list_my_tasks`

Purpose:

Return tasks assigned to the authenticated user in the current workspace.

Optional filters:

- status;
- priority.

---

## `get_task`

Input:

- task ID.

Return:

- task details;
- project;
- status;
- priority;
- assignee;
- due date;
- comments.

Must reject or hide tasks outside the current user's accessible workspace.

---

## `search_tasks`

Input:

- required search query;
- optional status;
- optional project.

Search at least:

- title;
- description.

Return only relevant concise results.

---

# 13. Required MCP Write Tools

## `create_task`

Inputs:

- project ID;
- title;
- description optional;
- priority optional;
- assignee ID optional;
- due date optional.

Requirements:

- owner/member only;
- project must belong to current workspace;
- assignee must belong to the same workspace;
- default status: `todo`;
- default priority: `medium`.

Return the newly created task.

---

## `update_task`

Use this only for normal editable task metadata.

Inputs:

- task ID;
- optional title;
- optional description;
- optional priority;
- optional due date.

Requirements:

- owner/member only;
- tenant scoped;
- validated.

Do not overload this tool with every possible task operation.

---

## `assign_task`

Inputs:

- task ID;
- assignee ID, nullable to unassign.

Requirements:

- owner/member only;
- assignee must belong to same workspace.

---

## `move_task`

Purpose:

Change task status.

Inputs:

- task ID;
- status.

Allowed status:

- todo;
- in_progress;
- done.

Owner/member only.

This intentionally exists separately from `update_task` to demonstrate semantic MCP tool design.

---

## `add_comment`

Inputs:

- task ID;
- body.

Owner/member only.

Set comment author from the authenticated user.

Do not accept arbitrary user ID.

---

## `delete_task`

Inputs:

- task ID.

Owner only.

Delete using normal Laravel authorization.

Prefer soft deletes only if they materially help the project. Otherwise normal deletion is acceptable.

Mark/configure this tool as destructive using the MCP package's supported tool metadata/annotations if available in the installed package version.

---

# 14. Conditional Tool Availability

Where supported by Laravel MCP, use conditional tool registration so that users do not receive tools they cannot use.

Desired behavior:

## Owner

Can discover:

- all read tools;
- create_task;
- update_task;
- assign_task;
- move_task;
- add_comment;
- delete_task.

## Member

Can discover:

- all read tools;
- create_task;
- update_task;
- assign_task;
- move_task;
- add_comment.

Must not discover:

- delete_task.

## Viewer

Can discover only read tools.

Must not discover write tools.

Even if a tool is somehow called manually, authorization must still deny the operation.

Tool hiding is not a security boundary.

---

# 15. MCP Resource

Create one useful MCP Resource demonstrating the difference between Tools and Resources.

## Project Instructions Resource

Conceptual URI:

```text
project://{project}/instructions
```

Return:

- project name;
- project description;
- project-specific AI instructions.

Requirements:

- project must belong to the authenticated user's current workspace;
- viewer/member/owner may read;
- no data from another workspace.

If the framework's preferred URI/resource conventions differ, use the idiomatic implementation while preserving the behavior.

---

# 16. MCP Prompt

Create one reusable MCP Prompt.

## `project_review`

Purpose:

Help an AI agent review a project.

Input:

- project ID.

The prompt should guide the client to:

1. inspect the project's open tasks;
2. identify overdue tasks;
3. identify high-priority unfinished tasks;
4. identify unassigned tasks;
5. summarize likely blockers;
6. recommend the next 3 actions.

The prompt should not directly mutate data.

It should guide the AI to use existing read tools/resources.

The project must be tenant scoped.

---

# 17. Authentication Architecture

The first implementation may use a simple authenticated web MCP setup supported by the installed Laravel MCP package.

However, structure the code so the MCP server can later demonstrate:

1. authenticated access;
2. Sanctum bearer-token authentication;
3. OAuth 2.1 / Passport authentication.

Do not hard-code API tokens.

Do not implement a fake custom authentication scheme.

If Laravel MCP's installed version provides official Passport/OAuth helpers, use those conventions.

Important:

Do not model application permissions as OAuth scopes.

OAuth establishes identity/access to the MCP server.

Workspace permissions remain in Laravel policies and membership roles.

---

# 18. MCP Error Handling

Errors should be useful to an AI agent.

Examples:

Bad:

```text
Invalid request.
```

Better:

```text
The assignee must be a member of the current workspace.
```

Bad:

```text
422
```

Better:

```text
The status must be one of: todo, in_progress, done.
```

Use Laravel validation wherever possible.

Do not leak sensitive existence information about resources in inaccessible workspaces.

Cross-tenant requests may be treated as not found.

---

# 19. MCP Response Design

Keep tool responses concise.

Avoid returning:

- timestamps unless useful;
- pivot metadata;
- internal Laravel attributes;
- huge comment histories by default;
- entire nested Eloquent models.

For mutations, return enough information for the agent to confirm what changed.

Example shape:

```json
{
  "id": 42,
  "title": "Investigate failed Stripe webhook",
  "status": "in_progress",
  "priority": "high",
  "project": {
    "id": 2,
    "name": "Billing Improvements"
  },
  "assignee": {
    "id": 7,
    "name": "Bob"
  }
}
```

Use structured output if the Laravel MCP package supports it cleanly.

---

# 20. Tool Safety Metadata

Use MCP tool annotations/metadata supported by the installed Laravel MCP version.

Examples conceptually:

Read tools:

```text
read-only
non-destructive
```

Create/update tools:

```text
not read-only
```

`delete_task`:

```text
destructive
```

Where appropriate, indicate idempotency accurately.

Do not treat metadata as authorization.

Authorization must still be enforced server-side.

---

# 21. Rate Limiting

Add reasonable rate limiting to the remote MCP endpoint.

The exact number is not important for the demo.

Use standard Laravel rate limiting.

The implementation should demonstrate that an MCP client or looping agent should not have unlimited request volume.

Document the limit in the README.

---

# 22. Audit Logging

Implement a minimal audit trail for MCP write actions.

Create a table such as:

## mcp_audit_logs

Fields:

- `id`
- `user_id`
- `workspace_id`
- `tool`
- `entity_type` nullable
- `entity_id` nullable
- `summary`
- timestamps

Record successful MCP write actions:

- create_task;
- update_task;
- assign_task;
- move_task;
- add_comment;
- delete_task.

Do not build a generic enterprise audit framework.

The purpose is to demonstrate a practical production consideration:

**AI agents can change data, so SaaS owners should be able to see what happened.**

Add a simple owner-only web page listing recent MCP actions for the current workspace.

---

# 23. Tests

Tests are a major deliverable.

Use Pest.

Do not consider the feature complete without meaningful tests.

---

## Domain tests

Cover:

- workspace memberships;
- roles;
- project ownership;
- task assignee must belong to workspace;
- comments.

---

## Policy tests

At minimum:

### Owner

- can view projects/tasks;
- can create/update/delete tasks;
- can manage projects;
- can comment.

### Member

- can view;
- can create/update tasks;
- can assign;
- can move;
- can comment;
- cannot delete task;
- cannot delete project.

### Viewer

- can view;
- cannot create;
- cannot update;
- cannot assign;
- cannot move;
- cannot comment;
- cannot delete.

---

## Tenant-isolation tests

Explicitly test:

- user in Workspace A cannot view Workspace B project;
- user in Workspace A cannot view Workspace B task;
- user cannot retrieve foreign task by guessing ID;
- user cannot update foreign task;
- user cannot delete foreign task;
- user cannot assign a Workspace B user to Workspace A task;
- MCP tools obey the same restrictions.

---

## MCP read-tool tests

Test:

- list_projects;
- list_tasks;
- list_my_tasks;
- get_task;
- search_tasks.

Verify both:

- correct data;
- no foreign workspace data.

---

## MCP write-tool tests

Test:

- create_task;
- update_task;
- assign_task;
- move_task;
- add_comment;
- delete_task.

Verify authorization for each role.

---

## MCP conditional registration tests

Where technically feasible with Laravel MCP:

- owner sees delete tool;
- member does not see delete tool;
- viewer does not see write tools.

If direct discovery-list assertions are not supported cleanly by the package's testing API, test the conditional registration classes/methods at the closest supported level.

---

## MCP validation tests

Include examples such as:

- missing task title;
- invalid status;
- invalid priority;
- assignee from another workspace;
- project from another workspace;
- invalid due date.

---

## MCP resource tests

Test project instructions:

- accessible in current workspace;
- inaccessible cross-tenant.

---

## MCP prompt tests

Test project review prompt:

- valid project;
- foreign project rejected;
- generated prompt references appropriate project context.

---

## Audit tests

Verify successful MCP mutations generate audit logs.

Failed/unauthorized operations should not be logged as successful actions.

---

# 24. Factories

Create factories for:

- Workspace;
- Project;
- Task;
- Comment.

Provide factory states where useful, such as:

```text
Task::factory()->todo()
Task::factory()->inProgress()
Task::factory()->done()
Task::factory()->highPriority()
```

Avoid excessive factory complexity.

---

# 25. README

Update the project README with:

## Setup

- install dependencies;
- configure `.env`;
- migrate;
- seed;
- run application.

## Demo accounts

List seeded login credentials.

## Domain

Briefly explain:

- workspaces;
- owner/member/viewer;
- projects;
- tasks.

## MCP

Explain:

- MCP server route/command;
- how to inspect it locally;
- how to connect an MCP-compatible client;
- authentication mode currently enabled;
- available tools;
- resource;
- prompt.

## Security model

Explicitly document:

```text
Authentication -> Who is the user?
Workspace membership -> Which tenant can they access?
Policies -> What can they do?
```

## Testing

Provide the command for running the Pest suite.

---

# 26. Code Quality

Before considering implementation complete, run the project's normal quality tools.

At minimum:

```bash
php artisan test
```

If available/configured in the repository, also run:

```bash
vendor/bin/pint
```

Use any existing static-analysis tooling if already configured.

Do not introduce new code-quality dependencies solely for this task unless necessary.

---

# 27. Out of Scope

Do NOT implement unless required by the Laravel MCP package itself:

- billing;
- Stripe integration;
- email invitations;
- real-time notifications;
- websockets;
- task attachments;
- labels/tags;
- subtasks;
- project templates;
- calendar UI;
- external integrations;
- Slack;
- GitHub;
- public REST API;
- mobile app;
- elaborate UI design;
- complex onboarding;
- impersonation;
- organization-level billing;
- custom OAuth scopes for owner/member/viewer;
- AI-generated task content.

Keep the project deliberately small.

---

# 28. Important Architectural Rule

The MCP layer should be a **thin agent-facing interface over normal application logic**.

Do not create a separate parallel implementation of TeamBoard business rules specifically for MCP.

For example:

Bad:

```text
Web action authorization logic
+
separate MCP authorization logic
+
separate MCP tenant logic
```

Preferred:

```text
Policies
Tenant-scoped application logic
Validation
        ↑
   reused by
   /      \
Web       MCP
```

The purpose of the demo is to show how an existing Laravel SaaS can safely become MCP-enabled.

---

# 29. Suggested Implementation Order

Implement in this order.

## Phase 1 — SaaS domain

1. enums;
2. migrations;
3. models and relationships;
4. factories;
5. seed data;
6. current workspace context;
7. policies;
8. domain tests.

Do not implement MCP until the normal application security model works.

---

## Phase 2 — Basic Livewire UI

1. workspace switcher;
2. dashboard;
3. projects;
4. tasks;
5. comments;
6. authorization in UI/actions.

Keep the UI minimal.

---

## Phase 3 — MCP read access

1. MCP server;
2. list_projects;
3. list_tasks;
4. list_my_tasks;
5. get_task;
6. search_tasks;
7. tests.

---

## Phase 4 — MCP write access

1. create_task;
2. update_task;
3. assign_task;
4. move_task;
5. add_comment;
6. delete_task;
7. tool annotations;
8. authorization tests.

---

## Phase 5 — MCP context

1. project instructions resource;
2. project review prompt;
3. tests.

---

## Phase 6 — Production considerations

1. conditional tool registration;
2. rate limiting;
3. MCP audit logs;
4. audit UI;
5. authentication documentation;
6. README;
7. final test/quality run.

---

# 30. Acceptance Criteria

The project is complete when all of the following are true:

- a user can belong to multiple workspaces;
- a workspace can have owners, members, and viewers;
- users can switch current workspace;
- projects and tasks are strictly tenant isolated;
- owner/member/viewer permissions work in the normal web application;
- MCP exposes useful task/project functionality;
- MCP uses authenticated-user context;
- read tools only return permitted workspace data;
- MCP write operations reuse Laravel authorization;
- viewers cannot mutate data;
- members cannot delete tasks;
- owners can delete tasks;
- unavailable write tools are conditionally hidden where supported;
- cross-tenant ID guessing does not expose data;
- project instructions are exposed through an MCP Resource;
- project review exists as an MCP Prompt;
- destructive actions are identified appropriately;
- MCP write operations create audit records;
- remote MCP access is rate limited;
- tests cover permissions and tenant isolation;
- `php artisan test` passes;
- README explains how to run and use the MCP server.

---

# 31. Final Instruction to the Coding Agent

Implement this application end-to-end.

Do not ask for approval after every phase.

Make sensible Laravel-native choices where the specification leaves minor implementation details open.

If the installed Laravel MCP package API differs from examples or assumptions in this specification, inspect the installed package/documentation and use the current official API rather than inventing methods.

Prioritize, in this order:

1. security and tenant isolation;
2. correctness;
3. test coverage;
4. clarity for teaching;
5. Laravel conventions;
6. minimal code;
7. UI polish.

Avoid feature creep.

The result should feel like a small real SaaS application whose most interesting feature is that AI agents can safely work with its data through MCP.
