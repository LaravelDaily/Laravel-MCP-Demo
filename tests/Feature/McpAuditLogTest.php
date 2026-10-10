<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Livewire\Tasks\Show as TaskShow;
use App\Mcp\Servers\TeamBoardServer;
use App\Mcp\Tools\AddCommentTool;
use App\Mcp\Tools\AssignTaskTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\DeleteTaskTool;
use App\Mcp\Tools\MoveTaskTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Comment;
use App\Models\McpAuditLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Testing\TestResponse;
use Livewire\Livewire;

function auditedToolAs(User $user, Workspace $workspace, string $tool, array $arguments): TestResponse
{
    $server = TeamBoardServer::actingAs($user);
    app(WorkspaceContext::class)->setExplicitWorkspace($workspace);

    return $server->tool($tool, $arguments);
}

dataset('audited write tools', [
    'create_task' => [CreateTaskTool::class, fn (Task $task): array => ['project_id' => $task->project_id, 'title' => 'Rolled back task']],
    'update_task' => [UpdateTaskTool::class, fn (Task $task): array => ['task_id' => $task->id, 'title' => 'Rolled back title']],
    'assign_task' => [AssignTaskTool::class, fn (Task $task): array => ['task_id' => $task->id, 'assignee_id' => null]],
    'move_task' => [MoveTaskTool::class, fn (Task $task): array => ['task_id' => $task->id, 'status' => 'done']],
    'add_comment' => [AddCommentTool::class, fn (Task $task): array => ['task_id' => $task->id, 'body' => 'Rolled back comment']],
    'delete_task' => [DeleteTaskTool::class, fn (Task $task): array => ['task_id' => $task->id]],
]);

test('logs exactly one audit entry for each successful MCP mutation', function (string $tool, Closure $arguments, Closure $expected) {
    $owner = User::factory()->create();
    $assignee = User::factory()->create(['name' => 'Bob Assignee']);
    $workspace = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->withMember($assignee, WorkspaceRole::Member)->create();
    $project = Project::factory()->for($workspace)->create(['name' => 'Audit project']);
    $task = Task::factory()->for($project)->assignedTo($owner)->create(['title' => 'Audited task', 'status' => TaskStatus::Todo]);

    $response = auditedToolAs($owner, $workspace, $tool, $arguments($task, $assignee));

    $response->assertOk();
    $audit = McpAuditLog::sole();
    expect($audit->only(['user_id', 'workspace_id', 'tool', 'entity_type', 'entity_id', 'summary']))
        ->toBe(['user_id' => $owner->id, 'workspace_id' => $workspace->id] + $expected($task, $assignee));
})->with([
    'create_task' => [CreateTaskTool::class, fn (Task $task): array => ['project_id' => $task->project_id, 'title' => 'Audit created task'], function (Task $task): array {
        $created = Task::query()->where('title', 'Audit created task')->sole();
        expect($created->project_id)->toBe($task->project_id);

        return ['tool' => 'create_task', 'entity_type' => 'task', 'entity_id' => $created->id, 'summary' => 'Created task #'.$created->id.' "Audit created task" in project "Audit project".'];
    }],
    'update_task' => [UpdateTaskTool::class, fn (Task $task): array => ['task_id' => $task->id, 'title' => 'Renamed audited task', 'priority' => 'high'], function (Task $task): array {
        expect($task->fresh()->only(['title', 'priority']))->toBe(['title' => 'Renamed audited task', 'priority' => TaskPriority::High]);

        return ['tool' => 'update_task', 'entity_type' => 'task', 'entity_id' => $task->id, 'summary' => 'Updated task #'.$task->id.' "Renamed audited task": title, priority.'];
    }],
    'assign_task' => [AssignTaskTool::class, fn (Task $task, User $assignee): array => ['task_id' => $task->id, 'assignee_id' => $assignee->id], function (Task $task, User $assignee): array {
        expect($task->fresh()->assignee_id)->toBe($assignee->id);

        return ['tool' => 'assign_task', 'entity_type' => 'task', 'entity_id' => $task->id, 'summary' => 'Assigned task #'.$task->id.' "Audited task" to Bob Assignee.'];
    }],
    'unassign through assign_task' => [AssignTaskTool::class, fn (Task $task): array => ['task_id' => $task->id, 'assignee_id' => null], function (Task $task): array {
        expect($task->fresh()->assignee_id)->toBeNull();

        return ['tool' => 'assign_task', 'entity_type' => 'task', 'entity_id' => $task->id, 'summary' => 'Unassigned task #'.$task->id.' "Audited task".'];
    }],
    'move_task' => [MoveTaskTool::class, fn (Task $task): array => ['task_id' => $task->id, 'status' => 'in_progress'], function (Task $task): array {
        expect($task->fresh()->status)->toBe(TaskStatus::InProgress);

        return ['tool' => 'move_task', 'entity_type' => 'task', 'entity_id' => $task->id, 'summary' => 'Moved task #'.$task->id.' "Audited task" to in_progress.'];
    }],
    'add_comment' => [AddCommentTool::class, fn (Task $task): array => ['task_id' => $task->id, 'body' => 'Audited comment body'], function (Task $task): array {
        $comment = $task->comments()->sole();
        expect($comment->body)->toBe('Audited comment body');

        return ['tool' => 'add_comment', 'entity_type' => 'comment', 'entity_id' => $comment->id, 'summary' => 'Commented on task #'.$task->id.' "Audited task".'];
    }],
    'delete_task' => [DeleteTaskTool::class, fn (Task $task): array => ['task_id' => $task->id], function (Task $task): array {
        expect(Task::query()->whereKey($task->id)->exists())->toBeFalse();

        return ['tool' => 'delete_task', 'entity_type' => 'task', 'entity_id' => $task->id, 'summary' => 'Deleted task #'.$task->id.' "Audited task" and its comments.'];
    }],
]);

test('logs another audit entry when a successful mutation is repeated', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();
    auditedToolAs($user, $workspace, MoveTaskTool::class, ['task_id' => $task->id, 'status' => 'done'])->assertOk();

    $response = auditedToolAs($user, $workspace, MoveTaskTool::class, ['task_id' => $task->id, 'status' => 'done']);

    $response->assertOk();
    expect(McpAuditLog::query()->where('tool', 'move_task')->where('entity_id', $task->id)->count())->toBe(2);
});

test('preserves task deletion history after deleting its comments', function () {
    $owner = User::factory()->create(['name' => 'Deleting Owner']);
    $workspace = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create(['title' => 'Short-lived launch task']);
    $comments = Comment::factory()->count(2)->for($task)->create();
    auditedToolAs($owner, $workspace, DeleteTaskTool::class, ['task_id' => $task->id])->assertOk();
    Auth::forgetGuards();
    $this->actingAs($owner);

    $page = $this->get(route('mcp-audit.index'));

    $this->assertModelMissing($task);
    $comments->each(fn (Comment $comment) => $this->assertModelMissing($comment));
    $this->assertDatabaseHas('mcp_audit_logs', [
        'tool' => 'delete_task', 'entity_type' => 'task', 'entity_id' => $task->id, 'user_id' => $owner->id, 'workspace_id' => $workspace->id,
        'summary' => 'Deleted task #'.$task->id.' "Short-lived launch task" and its comments.',
    ]);
    $page->assertOk()
        ->assertSee('Deleted task #'.$task->id.' &quot;Short-lived launch task&quot; and its comments.', false)
        ->assertSee('Deleting Owner');
});

test('does not audit reads resources prompts or web mutations as MCP writes', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->create();
    $project = Project::factory()->for($workspace)->create(['instructions' => 'Write acceptance criteria.']);
    $task = Task::factory()->for($project)->create();
    $deletable = Task::factory()->for($project)->create();
    $token = mcpAccessToken($owner);

    $reads = [
        mcpJsonRpc($token, $workspace, 'tools/call', ['name' => 'list_tasks', 'arguments' => (object) []]),
        mcpJsonRpc($token, $workspace, 'tools/call', ['name' => 'get_task', 'arguments' => ['task_id' => $task->id]]),
        mcpJsonRpc($token, $workspace, 'resources/read', ['uri' => 'project://'.$project->id.'/instructions']),
        mcpJsonRpc($token, $workspace, 'prompts/get', ['name' => 'project_review', 'arguments' => ['project' => (string) $project->id]]),
    ];
    Auth::forgetGuards();
    $this->actingAs($owner);
    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->set('status', 'done')
        ->call('changeStatus')
        ->set('body', 'Web comment')
        ->call('addComment')
        ->assertHasNoErrors();
    Livewire::test(TaskShow::class, ['taskId' => $deletable->id])
        ->call('delete');

    foreach ($reads as $response) {
        $response->assertOk()->assertJsonMissingPath('error');
    }
    expect($task->fresh()->status)->toBe(TaskStatus::Done);
    $this->assertDatabaseHas('comments', ['task_id' => $task->id, 'body' => 'Web comment']);
    $this->assertModelMissing($deletable);
    $this->assertDatabaseCount('mcp_audit_logs', 0);
});

test('does not create success audits for failed MCP mutations', function (string $name, Closure $arguments) {
    $member = User::factory()->create();
    $workspace = Workspace::factory()->withMember($member, WorkspaceRole::Member)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();
    $foreign = Task::factory()->create();
    $before = $task->fresh()->getAttributes();
    $foreignBefore = $foreign->fresh()->getAttributes();
    $token = mcpAccessToken($member);

    $response = mcpJsonRpc($token, $workspace, 'tools/call', ['name' => $name, 'arguments' => $arguments($task, $foreign)]);

    $response->assertJsonMissingPath('result.structuredContent');
    expect($task->fresh()->getAttributes())->toBe($before);
    expect($foreign->fresh()->getAttributes())->toBe($foreignBefore);
    expect(Task::count())->toBe(2);
    expect(Comment::count())->toBe(0);
    $this->assertDatabaseCount('mcp_audit_logs', 0);
})->with([
    'validation failure' => ['create_task', fn (Task $task, Task $foreign): array => ['project_id' => $task->project_id, 'title' => '   ']],
    'oversized comment' => ['add_comment', fn (Task $task, Task $foreign): array => ['task_id' => $task->id, 'body' => str_repeat('a', 5001)]],
    'foreign record' => ['move_task', fn (Task $task, Task $foreign): array => ['task_id' => $foreign->id, 'status' => 'done']],
    'missing record' => ['update_task', fn (Task $task, Task $foreign): array => ['task_id' => 999999, 'title' => 'Missing']],
    'hidden tool for role' => ['delete_task', fn (Task $task, Task $foreign): array => ['task_id' => $task->id]],
    'unknown tool' => ['drop_all_tasks', fn (Task $task, Task $foreign): array => ['task_id' => $task->id]],
]);

test('does not audit policy-denied MCP mutations', function (string $tool, Closure $arguments) {
    $viewer = User::factory()->create();
    $workspace = Workspace::factory()->withMember($viewer, WorkspaceRole::Viewer)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();
    $before = $task->fresh()->getAttributes();
    $this->actingAs($viewer);
    app(WorkspaceContext::class)->setExplicitWorkspace($workspace);

    $call = fn () => app($tool)->handle(new Request($arguments($task)));

    expect($call)->toThrow(AuthorizationException::class);
    expect($task->fresh()->getAttributes())->toBe($before);
    expect(Task::count())->toBe(1);
    expect(Comment::count())->toBe(0);
    $this->assertDatabaseCount('mcp_audit_logs', 0);
})->with('audited write tools');

test('rolls back domain mutations when audit persistence fails', function (string $tool, Closure $arguments) {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->assignedTo($owner)->create(['title' => 'Must survive']);
    $comments = Comment::factory()->count(2)->for($task)->create();
    $before = $task->fresh()->getAttributes();
    Exceptions::fake();
    Event::listen('eloquent.creating: '.McpAuditLog::class, function (): void {
        throw new RuntimeException('Audit storage unavailable');
    });

    $response = auditedToolAs($owner, $workspace, $tool, $arguments($task));

    $response->assertHasErrors(['Unable to complete this workspace operation. Please try again.'])
        ->assertDontSee('Audit storage unavailable');
    Exceptions::assertReported(RuntimeException::class);
    expect($task->fresh()?->getAttributes())->toBe($before);
    $comments->each(fn (Comment $comment) => $this->assertModelExists($comment));
    expect(Task::count())->toBe(1);
    expect(Comment::count())->toBe(2);
    $this->assertDatabaseCount('mcp_audit_logs', 0);
})->with('audited write tools');

test('keeps secrets and full content out of audit records', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->create();
    $project = Project::factory()->for($workspace)->create();
    $token = mcpAccessToken($owner);
    $description = str_repeat('Confidential roadmap detail. ', 300);
    $body = str_repeat('Sensitive customer quote. ', 190);

    $created = mcpJsonRpc($token, $workspace, 'tools/call', ['name' => 'create_task', 'arguments' => [
        'project_id' => $project->id, 'title' => 'Audited secret task', 'description' => $description,
    ]]);
    Auth::forgetGuards();
    $updated = mcpJsonRpc($token, $workspace, 'tools/call', ['name' => 'update_task', 'arguments' => [
        'task_id' => $created->json('result.structuredContent.id'), 'description' => $description.' Revised.',
    ]]);
    Auth::forgetGuards();
    $commented = mcpJsonRpc($token, $workspace, 'tools/call', ['name' => 'add_comment', 'arguments' => [
        'task_id' => $created->json('result.structuredContent.id'), 'body' => $body,
    ]]);

    $created->assertJsonPath('result.isError', false);
    $updated->assertJsonPath('result.isError', false);
    $commented->assertJsonPath('result.isError', false);
    $stored = McpAuditLog::query()->get()->toJson();
    expect(McpAuditLog::count())->toBe(3);
    expect($stored)
        ->not->toContain($token)
        ->not->toContain($owner->tokens()->sole()->id)
        ->not->toContain('Confidential roadmap detail')
        ->not->toContain('Sensitive customer quote');
});
