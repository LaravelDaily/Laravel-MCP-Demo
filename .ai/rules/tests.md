---
paths:
  - 'tests/**'
---

# Tests

## Build tenant-coherent test data and real membership changes
Create memberships with Workspace::factory()->withMember($user, $role). Assignees and comment authors must belong to the record's workspace (CommentFactory does this by default). Remove a membership with $workspace->members()->detach($user) or a Membership delete. WorkspaceContext caches per request, so assert behaviour after a membership or selection change across a new request (HTTP or Livewire call), not by calling current() again in the same request.

## Test MCP tools at both the primitive and HTTP boundary
Use the MCP primitive helpers (Server::actingAs()->tool()) for tool behavior, and real bearer-token HTTP JSON-RPC requests for authentication, workspace resolution, discovery, and middleware. Follow tests/Feature/TaskWriteToolsTest.php style: blank lines between arrange, act and assert; one statement per line; datasets or separate tests instead of branching. Never assert short strings that can match random Livewire IDs.
