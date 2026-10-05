I want to brainstorm the idea for a course for Laravel developers, which I would publish on my Laravel Daily website and maybe some YouTube videos. The premise is that a lot of SaaS startups, including the ones built with Laravel, need MCP servers now. It becomes a requirement so I want a practical course to teach exactly that but I want some research on two angles:
1. What would be the best example or a few examples of that? I'm thinking about something like a linear MCP or a similar project, which I would create as very, very simplified, almost like a to-do list or Trello board. The idea is that users would be able to use AI agents like Claude Code and Codex to connect to that project via MCP, accessing the data of only their team, with multi-tenancy and also playing around with permissions: who can read and write data for the task lists. Would you approve that example or would you add something else on top or some kind of plot twist?
2. Recently Laravel released the MCP package, version 1, officially so please do the research in its documentation. I want you to suggest to me the table of contents for such a course that would cover MCP from a practical angle. Probably one of the emphasised moments should be authentication but I'm not sure what else to emphasise or what the practical MCP usages or various different cases for real-life startups are that Laravel developers would build for their clients would be.

---

Yes. I would build the course around **one realistic SaaS application rather than several unrelated MCP demos**, and your simplified Linear idea is close to ideal.

Laravel MCP has also reached a good point for this: the official Laravel page currently lists v1.0.1, with tools, resources, prompts, OAuth/Sanctum auth, testing, local/web servers, streaming, and MCP UI apps all available. [Laravel](https://laravel.com/ai/mcp?utm_source=chatgpt.com)

[Laravel MCP documentation](https://laravel.com/docs/13.x/mcp?utm_source=chatgpt.com)

## 1. The demo project: Mini Linear

I'd call the conceptual project something like **TeamBoard** rather than literally cloning Linear.

The normal web application could stay deliberately boring:

**Workspace → Projects → Tasks → Comments**

Users belong to workspaces. A workspace has members with roles such as:

- **Owner/Admin** — everything
- **Member** — read + create/update tasks
- **Viewer** — read only

That's enough domain complexity. Don't build priorities, sprints, notifications, labels, attachments, etc. unless they're needed to demonstrate MCP.

The important part is that the course isn't really:

> "Build a task manager."

It's:

> **"Take an existing multi-tenant Laravel SaaS and make it usable by AI agents."**

That is much closer to what your students will actually need to do for clients.

### Why Linear is an especially good model

Real [Linear MCP](https://linear.app/docs/mcp?utm_source=chatgpt.com) exposes tools for finding, creating and updating issues/projects/comments. It uses a remote Streamable HTTP MCP endpoint and OAuth 2.1. Interestingly, Linear even provides a dedicated read-only MCP endpoint. [Linear](https://linear.app/docs/mcp?utm_source=chatgpt.com)

Their documented workflows aren't artificial MCP demos either. They show things such as taking a planning document and creating a project/issues, converting standup notes into issue updates, investigating bugs and writing findings back to issues, and summarizing completed work. [Linear](https://linear.app/docs/mcp?utm_source=chatgpt.com)

That's exactly the type of experience I'd reproduce on a smaller scale.

---

# 2. Start the course with the "magic moment"

I wouldn't start with 30 minutes explaining the MCP protocol.

Build a tiny unauthenticated/local version first and get this working:

> **User in Claude Code:**  
> "What tasks are currently assigned to me?"

Agent calls:

`list_tasks`

Then:

> "Create a task to investigate the failed Stripe webhook and assign it to me."

Agent calls:

`create_task`.

That immediately answers the student's question:

**"Why does my SaaS need MCP if I already have a REST API?"**

Laravel itself makes essentially this distinction: MCP becomes particularly valuable when the consumer is an end user using AI clients rather than a developer manually calling an API. [Laravel](https://laravel.com/blog/laravel-mcp-a-complete-guide?utm_source=chatgpt.com)

Then spend the rest of the course turning that toy integration into something you'd actually ship.

---

# 3. Suggested course project

I'd gradually arrive at approximately these MCP capabilities:

```text
READ

list_projects
list_tasks
get_task
search_tasks
list_my_tasks

WRITE

create_task
update_task
assign_task
add_comment
complete_task

DANGEROUS

delete_task
```

But there's a very useful teaching opportunity here:

### Don't simply expose CRUD.

For example, compare:

```text
update_task(id, fields)
```

versus purpose-built operations:

```text
assign_task
complete_task
move_task
add_comment
```

That becomes a lesson about **designing APIs for agents**.

Agents benefit from narrowly defined tools with strong schemas and very clear descriptions. Laravel MCP supports JSON schemas, Laravel validation and explicit error responses. Its documentation specifically notes that actionable validation errors matter because AI clients react to those messages. [Laravel](https://laravel.com/framework/docs/10.x/mcp)

That topic alone would make the course more valuable than "here's how the package syntax works."

---

# 4. The main plot twist: "Now make it a real SaaS"

This is where I'd spend **a large percentage of the course**.

Initially:

```text
Claude
   ↓
MCP
   ↓
Tasks
```

Everything works.

Then introduce:

```text
Workspace A
    Povilas - Admin
    John    - Member
    Mary    - Viewer

Workspace B
    Bob     - Admin
```

Now suddenly seemingly simple questions become interesting.

Can Povilas ask:

> "Show me task #137."

What happens if task #137 belongs to Workspace B?

Can Mary say:

> "Complete task #25."

Can John delete a project?

Can an agent enumerate IDs until it discovers another tenant's data?

Can a Viewer even **see** `delete_task` in their tool list?

That last one is especially interesting because Laravel MCP supports conditional tool registration through `shouldRegister()`: unavailable tools don't merely fail when invoked; they can be completely absent from the tool list. [Laravel](https://laravel.com/framework/docs/10.x/mcp)

So you could demonstrate:

```text
Owner MCP tools
---------------
list_tasks
create_task
update_task
delete_task


Viewer MCP tools
----------------
list_tasks
```

That's a fantastic practical Laravel lesson.

---

# 5. Authentication deserves its own module

I'd make this one of the course's major sections.

Start with **Sanctum token authentication**, because developers immediately understand it:

```text
Authorization: Bearer xxx
```

Laravel MCP supports Sanctum or arbitrary token middleware. [Laravel](https://laravel.com/framework/docs/10.x/mcp)

Then say:

> "This works, but I don't want customers copying API tokens into Claude."

Now upgrade the application to **OAuth 2.1 with Passport**.

The resulting user experience becomes:

```text
Claude / Codex
      ↓
Connect TeamBoard
      ↓
Browser opens
      ↓
Login to TeamBoard
      ↓
"Allow Claude to access TeamBoard?"
      ↓
Approve
      ↓
Connected
```

That's the production SaaS story.

Laravel's implementation handles the MCP OAuth discovery routes and dynamic client registration; Passport then handles the authorization flow. The Laravel security guide describes the sequence from the initial 401 through discovery, dynamic registration, browser approval, PKCE and finally bearer-token requests. [Laravel](https://laravel.com/blog/laravel-mcp-server-auth-security-best-practices?utm_source=chatgpt.com)

This would probably be one of your strongest YouTube videos independently:

**"Add OAuth Authentication to Your Laravel MCP Server"**

---

# 6. Authentication ≠ authorization

I'd explicitly title a lesson that.

This distinction will save people from real security bugs.

```text
Authentication
"Who is calling?"

OAuth / Passport
        ↓
User #42


Authorization
"What can User #42 do?"

User
 ↓
Workspace membership
 ↓
Role
 ↓
Laravel Policy
 ↓
Task
```

Laravel MCP gives you the authenticated user through `$request->user()`, and normal Laravel authorization can then be applied. [Laravel](https://laravel.com/framework/docs/10.x/mcp)

As mentioned earlier, there's an important current limitation worth teaching: Laravel MCP's Passport integration currently advertises one `mcp:use` scope and does not support arbitrary custom OAuth scopes in this integration. [Laravel](https://laravel.com/framework/docs/10.x/mcp)

So I wouldn't architect the course around:

`tasks:read / tasks:write / tasks:delete`

as Passport scopes.

Instead:

**OAuth establishes identity → your SaaS's policies establish permissions.**

That's a much more reusable lesson anyway.

---

# 7. Tools vs Resources vs Prompts

This should be taught through the application instead of definitions.

### Tools

Actions/query operations:

```text
search_tasks
create_task
assign_task
complete_task
```

### Resources

Context that an agent might consume:

```text
workspace://current
project://42
project://42/guidelines
```

For example, perhaps every project contains an "AI Instructions" field:

> When creating tasks for this project, always add acceptance criteria.

Expose that as an MCP Resource.

Laravel resources are URI-addressable readable data and support the same dependency injection/conditional registration patterns. [Laravel](https://laravel.com/blog/laravel-mcp-a-complete-guide?utm_source=chatgpt.com)

### Prompts

Then something like:

**Weekly Project Review**

The MCP prompt tells the agent:

> Review open tasks, identify blocked work and summarize what should be prioritized next week.

Laravel MCP supports reusable prompts with arguments, validation, dependency injection and conditional registration. [Laravel](https://laravel.com/framework/docs/10.x/mcp)

This way students understand **why the three primitives exist**, rather than memorizing their APIs.

---

# 8. A surprisingly important lesson: agent-friendly tool design

I'd dedicate an entire section to this.

Show a bad version first:

```text
manage_task(action, data)
```

Then improve it.

Talk about:

**Names**

`create_task` is better than `task_operation`.

**Descriptions**

Tell the model exactly when it should use the tool.

**Schemas**

Don't accept arbitrary blobs.

**Validation errors**

Give the agent enough information to correct itself.

**Response size**

Don't dump 300 complete Eloquent models into context.

**Pagination/search**

`search_tasks(query, status, assignee)`.

**Idempotency**

What happens when the agent retries?

Laravel MCP actually exposes annotations for precisely these semantics:

`IsReadOnly`, `IsDestructive`, `IsIdempotent`, and `IsOpenWorld`. [Laravel](https://laravel.com/framework/docs/10.x/mcp)

Important caveat: MCP describes these annotations as **hints, not security enforcement**. [Model Context Protocol Blog](https://blog.modelcontextprotocol.io/posts/2026-03-16-tool-annotations/?utm_source=chatgpt.com)

That's a nice senior-level detail.

---

# 9. Make one lesson deliberately dangerous

This would make a good video too.

Give the MCP server:

```text
delete_task
```

Then ask the agent something ambiguous:

> "Clean up all those old tasks."

What should happen?

Now introduce:

```php
#[IsDestructive]
```

and discuss why destructive agent actions require more care.

You can expand that into:

- authorization
- confirmation
- audit logs
- soft deletes
- idempotency
- rate limiting
- limiting bulk operations
- not trusting AI input merely because it came through an MCP client

Laravel's own production example specifically calls out rate limiting because an incorrectly configured client or agent loop can make large numbers of MCP calls. [Laravel](https://laravel.com/blog/deploy-a-laravel-mcp-app-to-production-with-laravel-cloud?utm_source=chatgpt.com)

That makes the course feel much more **production-oriented**.

---

# 10. Testing should be substantial

Another place I'd differentiate your course from tutorials.

First:

```bash
php artisan mcp:inspector teamboard
```

Laravel ships MCP Inspector integration for trying tools/resources/prompts and debugging authentication. [Laravel](https://laravel.com/framework/docs/10.x/mcp)

Then Pest tests.

Especially tests like:

```text
✓ admin sees workspace tasks
✓ member cannot access another workspace
✓ viewer can list tasks
✓ viewer cannot update task
✓ viewer doesn't receive destructive tool
✓ user cannot guess another tenant's task ID
✓ invalid arguments return useful error
✓ create task actually creates correct tenant record
```

Laravel's test API supports invoking MCP primitives directly and `actingAs($user)`, making those authorization tests particularly natural. [Laravel](https://laravel.com/framework/docs/10.x/mcp)

For your audience, I think this would be more valuable than spending much time explaining JSON-RPC internals.

---

# 11. I'd add MCP UI Apps — but as a bonus

Laravel added MCP UI App support this year: tools can return interactive HTML experiences rendered inside compatible MCP hosts. [Laravel](https://laravel.com/blog/laravel-april-product-updates?utm_source=chatgpt.com)

For TeamBoard, imagine:

> "Show me the state of Project Alpha."

Instead of Claude returning:

```text
Todo: 12
In progress: 7
Done: 32
```

it could render a little Kanban/dashboard UI inside the MCP client.

That is cool enough for:

**Bonus: Your MCP Doesn't Have to Return Just Text**

But I wouldn't put it in the core path. Authentication/authorization/tool design are much more universally useful.

---

# My proposed course TOC

I would probably aim for **~15 lessons**, not 30 tiny documentation lessons.

1. **Why Your Laravel SaaS Needs an MCP Server** — API vs MCP; connect the first simple server to Claude Code/Codex.
2. **Our Project: Multi-Tenant TeamBoard** — existing Laravel SaaS, workspaces, projects, tasks, memberships.
3. **Your First Laravel MCP Server** — install `laravel/mcp`, `routes/ai.php`, web vs local servers.
4. **Your First Tool: List Tasks** — tool structure, descriptions, input/output.
5. **Building Useful Read Tools** — filtering, search, schemas, validation, structured responses.
6. **Write Tools: Let the Agent Create and Update Tasks** — create, assign, comment, complete.
7. **Designing MCP Tools for AI, Not Humans** — naming, granularity, descriptions, schemas, errors, context size, idempotency.
8. **Authentication Part 1: Sanctum Tokens** — protected remote MCP server.
9. **Authentication Part 2: OAuth 2.1 + Passport** — real "Connect to TeamBoard" browser authorization.
10. **Multi-Tenancy: Never Leak Another Team's Data** — tenant resolution, query scoping, ID enumeration.
11. **Authorization: Different Tools for Different Users** — policies/gates, viewer/member/admin, `shouldRegister()`.
12. **Resources and Prompts: Giving Agents Context** — project instructions/resources + reusable project-review prompt.
13. **Dangerous MCP Tools** — destructive actions, annotations, confirmations, rate limiting, audit trail.
14. **Testing Your MCP Server** — Inspector + Pest; authentication, authorization and tenant-isolation tests.
15. **Production: Connect Claude Code and Codex to the Real Server** — deploy, OAuth, HTTPS, debugging, final end-to-end workflows.

Then I'd have two optional lessons:

**Bonus A — Streaming Long-Running MCP Operations.** Laravel tools can yield progress notifications, and web servers automatically stream those responses over SSE. [Laravel](https://laravel.com/framework/docs/10.x/mcp)

**Bonus B — MCP UI Apps.** Render an actual task/project dashboard inside a compatible AI host. [Laravel](https://laravel.com/blog/the-easiest-and-fastest-way-to-build-mcp-and-claude-apps?utm_source=chatgpt.com)

---

## One change I'd make to your original premise

I wouldn't market it primarily as:

> **"Learn Laravel MCP."**

That sounds like package documentation.

I'd position it more like:

> **Build an MCP Server for Your Laravel SaaS**

or:

> **Make Your Laravel SaaS AI-Agent Ready: MCP from Development to Production**

Because the valuable skill isn't knowing `laravel/mcp` syntax. The student should finish knowing how to take **their existing CRM, project manager, invoicing system, helpdesk, e-commerce back office, HR platform, etc. and safely expose useful functionality to Claude, Codex, ChatGPT and future MCP clients.**

And I'd keep **one TeamBoard project throughout the entire course**. It starts absurdly simple — `list_tasks` — and ends with OAuth, multi-tenancy, permissions, destructive operations and tests. That progression gives you a much stronger story than five separate weather/orders/CRM examples.

One additional idea I particularly like for your audience: in the final lesson, give students an **existing ordinary Laravel SaaS without MCP** and make the assignment: *"Design its MCP surface before writing any code."* Decide which capabilities should be tools, which should be resources, which should not be exposed at all, and where authorization belongs. That tests whether they've learned **MCP architecture**, rather than merely copied Laravel code.