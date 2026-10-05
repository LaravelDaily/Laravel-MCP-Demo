---
paths:
  - 'app/**'
---

# App

## Resolve the workspace only through WorkspaceContext
Get the current workspace and its scoped projects/tasks from App\Workspaces\WorkspaceContext (current(), currentOrFail(), projects(), tasks(), switchTo(), setExplicitWorkspace()). Do not write another resolver or read the session key directly. Look up tenant records through the context's scoped relations, never globally followed by a policy check. The resolution is cached on the current Request's attributes; keep any new per-request tenant state there, not in Context, singletons, or static properties.

## Reuse policies and role helpers for permissions
Authorize through the policies (Workspace/Project/Task/Comment). Policies resolve roles with User::roleIn(Workspace|int), and WorkspaceRole::canContribute() means owner or member. Don't repeat role comparisons in components, actions, or MCP tools. Pass workspace_id instead of loading the workspace model just to check a role.

## Inaccessible tenants are 404, role denials are 403
Foreign and missing workspaces/records must raise the same not-found error (ModelNotFoundException → 404), so a response never reveals that a record exists. Use 403 (AuthorizationException) only when the record is in an accessible workspace and the role lacks the ability.
