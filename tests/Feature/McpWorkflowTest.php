<?php

use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\McpAuditLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

function workflowToolCall(string $token, Workspace $workspace, string $tool, array $arguments): TestResponse
{
    Auth::forgetGuards();
    test()->travel(1)->seconds();

    return mcpJsonRpc($token, $workspace, 'tools/call', ['name' => $tool, 'arguments' => $arguments]);
}

test('completes an authenticated MCP task workflow with an audit trail', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $owner = User::factory()->create(['name' => 'Alice Owner']);
    $bob = User::factory()->create(['name' => 'Bob Member']);
    $workspace = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->withMember($bob, WorkspaceRole::Member)->create();
    $project = Project::factory()->for($workspace)->create(['name' => 'Website Redesign']);
    $untouched = Task::factory()->for($project)->create(['title' => 'Existing seeded-style task']);
    $untouchedBefore = $untouched->fresh()->getAttributes();
    $token = $owner->createToken('Workflow client')->plainTextToken;

    $created = workflowToolCall($token, $workspace, 'create_task', ['project_id' => $project->id, 'title' => 'Workflow task', 'description' => 'Acceptance criteria: works end to end.']);
    $taskId = $created->json('result.structuredContent.id');
    $members = workflowToolCall($token, $workspace, 'list_workspace_members', ['search' => 'Bob']);
    $bobId = $members->json('result.structuredContent.data.0.id');
    $assigned = workflowToolCall($token, $workspace, 'assign_task', ['task_id' => $taskId, 'assignee_id' => $bobId]);
    $moved = workflowToolCall($token, $workspace, 'move_task', ['task_id' => $taskId, 'status' => 'in_progress']);
    $commented = workflowToolCall($token, $workspace, 'add_comment', ['task_id' => $taskId, 'body' => 'Workflow checked by the agent.']);
    $commentId = $commented->json('result.structuredContent.id');
    $read = workflowToolCall($token, $workspace, 'get_task', ['task_id' => $taskId]);
    $deleted = workflowToolCall($token, $workspace, 'delete_task', ['task_id' => $taskId]);
    $missing = workflowToolCall($token, $workspace, 'get_task', ['task_id' => $taskId]);

    $created->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.title', 'Workflow task')
        ->assertJsonPath('result.structuredContent.status', 'todo');
    $members->assertOk()
        ->assertJsonPath('result.structuredContent.data', [['id' => $bob->id, 'name' => 'Bob Member', 'role' => 'member']]);
    $assigned->assertOk()
        ->assertJsonPath('result.structuredContent.assignee', ['id' => $bob->id, 'name' => 'Bob Member']);
    $moved->assertOk()
        ->assertJsonPath('result.structuredContent.status', 'in_progress');
    $commented->assertOk()
        ->assertJsonPath('result.structuredContent.author', ['id' => $owner->id, 'name' => 'Alice Owner']);
    $read->assertOk()
        ->assertJsonPath('result.structuredContent.status', 'in_progress')
        ->assertJsonPath('result.structuredContent.assignee.id', $bob->id)
        ->assertJsonPath('result.structuredContent.description', 'Acceptance criteria: works end to end.')
        ->assertJsonPath('result.structuredContent.comments_total', 1)
        ->assertJsonPath('result.structuredContent.comments.0.body', 'Workflow checked by the agent.');
    $deleted->assertOk()
        ->assertJsonPath('result.structuredContent', ['deleted' => true, 'task' => ['id' => $taskId, 'title' => 'Workflow task']]);
    $missing->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'The record was not found in the current workspace.');
    $this->assertDatabaseMissing('tasks', ['id' => $taskId]);
    $this->assertDatabaseMissing('comments', ['id' => $commentId]);
    expect($untouched->fresh()->getAttributes())->toBe($untouchedBefore);
    expect(Comment::count())->toBe(0);
    expect(McpAuditLog::query()->orderBy('id')->get()->map->only(['user_id', 'workspace_id', 'tool', 'entity_type', 'entity_id', 'summary'])->all())->toBe([
        ['user_id' => $owner->id, 'workspace_id' => $workspace->id, 'tool' => 'create_task', 'entity_type' => 'task', 'entity_id' => $taskId, 'summary' => 'Created task #'.$taskId.' "Workflow task" in project "Website Redesign".'],
        ['user_id' => $owner->id, 'workspace_id' => $workspace->id, 'tool' => 'assign_task', 'entity_type' => 'task', 'entity_id' => $taskId, 'summary' => 'Assigned task #'.$taskId.' "Workflow task" to Bob Member.'],
        ['user_id' => $owner->id, 'workspace_id' => $workspace->id, 'tool' => 'move_task', 'entity_type' => 'task', 'entity_id' => $taskId, 'summary' => 'Moved task #'.$taskId.' "Workflow task" to in_progress.'],
        ['user_id' => $owner->id, 'workspace_id' => $workspace->id, 'tool' => 'add_comment', 'entity_type' => 'comment', 'entity_id' => $commentId, 'summary' => 'Commented on task #'.$taskId.' "Workflow task".'],
        ['user_id' => $owner->id, 'workspace_id' => $workspace->id, 'tool' => 'delete_task', 'entity_type' => 'task', 'entity_id' => $taskId, 'summary' => 'Deleted task #'.$taskId.' "Workflow task" and its comments.'],
    ]);

    Auth::forgetGuards();
    $page = $this->actingAs($owner)->get(route('mcp-audit.index'));

    $page->assertOk()
        ->assertSeeInOrder(['delete_task', 'add_comment', 'move_task', 'assign_task', 'create_task'])
        ->assertSee('2026-10-05 09:00:07 UTC');
});
