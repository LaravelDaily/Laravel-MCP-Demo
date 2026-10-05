<?php

use App\Enums\TaskPriority;
use App\Enums\WorkspaceRole;
use App\Livewire\Tasks\Show as TaskShow;
use App\Mcp\Servers\TeamBoardServer;
use App\Mcp\Tools\AddCommentTool;
use App\Mcp\Tools\AssignTaskTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\MoveTaskTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Mcp\Server\Testing\TestResponse;
use Livewire\Livewire;

function taskWriteAs(User $user, Workspace $workspace, string $tool, array $arguments): TestResponse
{
    $server = TeamBoardServer::actingAs($user);
    app(WorkspaceContext::class)->setExplicitWorkspace($workspace);

    return $server->tool($tool, $arguments);
}

dataset('metadata write tools', [
    'create_task' => [CreateTaskTool::class, 'create_task', fn (Task $task): array => ['project_id' => $task->project_id, 'title' => 'New task']],
    'update_task' => [UpdateTaskTool::class, 'update_task', fn (Task $task): array => ['task_id' => $task->id, 'title' => 'Changed task']],
]);

dataset('ordinary write tools', [
    'create_task' => [CreateTaskTool::class, 'create_task', fn (Task $task): array => ['project_id' => $task->project_id, 'title' => 'New task']],
    'update_task' => [UpdateTaskTool::class, 'update_task', fn (Task $task): array => ['task_id' => $task->id, 'title' => 'Changed task']],
    'assign_task' => [AssignTaskTool::class, 'assign_task', fn (Task $task): array => ['task_id' => $task->id, 'assignee_id' => null]],
    'move_task' => [MoveTaskTool::class, 'move_task', fn (Task $task): array => ['task_id' => $task->id, 'status' => 'done']],
    'add_comment' => [AddCommentTool::class, 'add_comment', fn (Task $task): array => ['task_id' => $task->id, 'body' => 'New comment']],
]);

test('creates a task in the selected project for owners and members', function (WorkspaceRole $role) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, $role)->create();
    $project = Project::factory()->for($workspace)->create(['name' => 'Selected project']);
    $unrelated = Task::factory()->create();
    $before = $unrelated->fresh()->getAttributes();

    $response = taskWriteAs($user, $workspace, CreateTaskTool::class, ['project_id' => $project->id, 'title' => '  Newly created task  ']);

    $task = $project->tasks()->sole();
    $response->assertOk()
        ->assertStructuredContent([
            'id' => $task->id,
            'title' => 'Newly created task',
            'project' => ['id' => $project->id, 'name' => 'Selected project'],
            'status' => 'todo',
            'priority' => 'medium',
            'assignee' => null,
            'due_date' => null,
            'description' => null,
        ]);
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id, 'project_id' => $project->id, 'title' => 'Newly created task',
        'status' => 'todo', 'priority' => 'medium', 'description' => null, 'due_date' => null, 'assignee_id' => null,
    ]);
    expect($unrelated->fresh()->getAttributes())->toBe($before);
    expect(Task::count())->toBe(2);
})->with([WorkspaceRole::Owner, WorkspaceRole::Member]);

test('creates task metadata and permits a viewer assignee', function () {
    $this->travelTo('2026-10-05');
    $user = User::factory()->create();
    $viewer = User::factory()->create(['name' => 'Assignable viewer']);
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->withMember($viewer, WorkspaceRole::Viewer)->create();
    $project = Project::factory()->for($workspace)->create();

    $response = taskWriteAs($user, $workspace, CreateTaskTool::class, [
        'project_id' => $project->id, 'title' => 'Metadata task', 'description' => 'Acceptance criteria',
        'priority' => 'high', 'due_date' => '2026-10-01', 'assignee_id' => $viewer->id,
    ]);

    $task = $project->tasks()->sole();
    $response->assertOk()
        ->assertStructuredContent([
            'id' => $task->id, 'title' => 'Metadata task', 'project' => ['id' => $project->id, 'name' => $project->name],
            'status' => 'todo', 'priority' => 'high', 'assignee' => ['id' => $viewer->id, 'name' => 'Assignable viewer'],
            'due_date' => '2026-10-01', 'description' => 'Acceptance criteria',
        ]);
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id, 'project_id' => $project->id, 'title' => 'Metadata task', 'status' => 'todo',
        'priority' => 'high', 'assignee_id' => $viewer->id, 'description' => 'Acceptance criteria', 'due_date' => '2026-10-01',
    ]);
});

test('accepts explicit null for nullable creation metadata', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $project = Project::factory()->for($workspace)->create();

    $response = taskWriteAs($user, $workspace, CreateTaskTool::class, [
        'project_id' => $project->id, 'title' => 'Nullable task', 'description' => null, 'due_date' => null, 'assignee_id' => null,
    ]);

    $response->assertOk();
    $task = $project->tasks()->sole();
    expect($task->description)->toBeNull();
    expect($task->due_date)->toBeNull();
    expect($task->assignee_id)->toBeNull();
});

test('rejects invalid task creation without persisting a task', function (array $input, string $message) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $project = Project::factory()->for($workspace)->create();

    $response = taskWriteAs($user, $workspace, CreateTaskTool::class, $input + ['project_id' => $project->id, 'title' => 'Valid title']);

    $response->assertHasErrors([$message]);
    expect(Task::count())->toBe(0);
    expect(Comment::count())->toBe(0);
})->with([
    'missing project' => [['project_id' => null], 'The project id field is required.'],
    'string project' => [['project_id' => '1'], 'The project id field must be an integer.'],
    'non-positive project' => [['project_id' => 0], 'The project id field must be at least 1.'],
    'blank title' => [['title' => '   '], 'The title field is required.'],
    'null title' => [['title' => null], 'The title field is required.'],
    'non-string title' => [['title' => 123], 'The title field must be a string.'],
    'long title' => [['title' => str_repeat('a', 256)], 'The title field must not be greater than 255 characters.'],
    'long description' => [['description' => str_repeat('a', 10001)], 'The description field must not be greater than 10000 characters.'],
    'invalid priority' => [['priority' => 'urgent'], 'The priority must be one of: low, medium, high.'],
    'null priority' => [['priority' => null], 'The priority must be one of: low, medium, high.'],
    'invalid date format' => [['due_date' => '10/05/2026'], 'The due date field must match the format Y-m-d.'],
    'impossible date' => [['due_date' => '2026-02-30'], 'The due date field must match the format Y-m-d.'],
    'string assignee' => [['assignee_id' => '1'], 'The assignee id field must be an integer.'],
    'zero assignee' => [['assignee_id' => 0], 'The assignee id field must be at least 1.'],
    'caller status' => [['status' => 'done'], 'The status field is not supported here.'],
]);

test('rejects missing creation arguments together', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();

    $response = taskWriteAs($user, $workspace, CreateTaskTool::class, []);

    $response->assertHasErrors(['The project id field is required.', 'The title field is required.']);
    expect(Task::count())->toBe(0);
});

test('rejects a foreign creation assignee without creating a task', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $project = Project::factory()->for($workspace)->create();
    $foreign = User::factory()->create();
    Workspace::factory()->withMember($foreign, WorkspaceRole::Viewer)->create();

    $response = taskWriteAs($user, $workspace, CreateTaskTool::class, ['project_id' => $project->id, 'title' => 'Rejected task', 'assignee_id' => $foreign->id]);

    $response->assertHasErrors(['The assignee must be a member of the current workspace.']);
    expect(Task::count())->toBe(0);
});

test('updates only submitted task metadata', function (WorkspaceRole $role) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, $role)->create();
    $project = Project::factory()->for($workspace)->create();
    $task = Task::factory()->for($project)->assignedTo($user)->done()->create([
        'title' => 'Before title', 'description' => 'Preserved description', 'priority' => TaskPriority::Low, 'due_date' => '2026-10-10',
    ]);
    $unrelated = Task::factory()->for($project)->create();
    $before = $unrelated->fresh()->getAttributes();

    $response = taskWriteAs($user, $workspace, UpdateTaskTool::class, ['task_id' => $task->id, 'title' => '  After title  ', 'priority' => 'high']);

    $response->assertOk()
        ->assertStructuredContent([
            'id' => $task->id, 'title' => 'After title', 'project' => ['id' => $project->id, 'name' => $project->name],
            'status' => 'done', 'priority' => 'high', 'assignee' => ['id' => $user->id, 'name' => $user->name],
            'due_date' => '2026-10-10', 'description' => 'Preserved description',
        ]);
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id, 'title' => 'After title', 'priority' => 'high', 'project_id' => $project->id,
        'status' => 'done', 'assignee_id' => $user->id, 'due_date' => '2026-10-10', 'description' => 'Preserved description',
    ]);
    expect($unrelated->fresh()->getAttributes())->toBe($before);
})->with([WorkspaceRole::Owner, WorkspaceRole::Member]);

test('clears nullable task metadata with explicit null', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create(['description' => 'Clear me', 'due_date' => '2026-10-10']);
    $before = $task->fresh()->getAttributes();

    $response = taskWriteAs($user, $workspace, UpdateTaskTool::class, ['task_id' => $task->id, 'description' => null, 'due_date' => null]);

    $response->assertOk();
    $after = $task->fresh();
    expect($after->description)->toBeNull();
    expect($after->due_date)->toBeNull();
    expect($after->getAttributes())->toMatchArray(array_replace($before, ['description' => null, 'due_date' => null, 'updated_at' => $after->getRawOriginal('updated_at')]));
});

test('rejects invalid updates without partially saving metadata', function (array $input, string $message) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create(['title' => 'Original title']);
    $before = $task->fresh()->getAttributes();

    $response = taskWriteAs($user, $workspace, UpdateTaskTool::class, ['task_id' => $task->id] + $input);

    $response->assertHasErrors([$message]);
    expect($task->fresh()->getAttributes())->toBe($before);
})->with([
    'empty metadata' => [[], 'At least one task field is required.'],
    'null title' => [['title' => null], 'The title field is required.'],
    'blank title' => [['title' => '  '], 'The title field is required.'],
    'null priority' => [['priority' => null], 'The priority must be one of: low, medium, high.'],
    'invalid priority' => [['title' => 'Must not persist', 'priority' => 'urgent'], 'The priority must be one of: low, medium, high.'],
    'invalid date' => [['title' => 'Must not persist', 'due_date' => 'tomorrow'], 'The due date field must match the format Y-m-d.'],
    'long description' => [['title' => 'Must not persist', 'description' => str_repeat('a', 10001)], 'The description field must not be greater than 10000 characters.'],
]);

test('rejects status assignment and ownership changes through update_task', function (string $field) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();
    $before = $task->fresh()->getAttributes();

    $response = taskWriteAs($user, $workspace, UpdateTaskTool::class, ['task_id' => $task->id, 'title' => 'Rejected rename', $field => 999]);

    $response->assertHasErrors(['The '.$field.' field is not supported here.']);
    expect($task->fresh()->getAttributes())->toBe($before);
})->with(['status', 'assignee_id', 'project_id']);

test('rejects actor and workspace arguments together in ordinary writes', function (string $tool, string $name, Closure $arguments) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();
    $before = $task->fresh()->getAttributes();

    $response = taskWriteAs($user, $workspace, $tool, $arguments($task) + ['actor_id' => 999, 'workspace_id' => 999]);

    $response->assertHasErrors(['The actor_id field is not supported here.', 'The workspace_id field is not supported here.']);
    expect($task->fresh()->getAttributes())->toBe($before);
    expect(Task::count())->toBe(1);
    expect(Comment::count())->toBe(0);
})->with('ordinary write tools');

test('forbids viewer calls to every write operation', function (string $tool, string $name, Closure $arguments) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();
    $before = $task->fresh()->getAttributes();
    $token = $user->createToken('Viewer write boundary')->plainTextToken;

    $response = $this->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments($task)],
    ], ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', match ($name) {
            'create_task' => 'Your workspace role cannot create tasks.',
            'update_task' => 'Your workspace role cannot update tasks.',
            'assign_task' => 'Your workspace role cannot assign tasks.',
            'move_task' => 'Your workspace role cannot change task status.',
            'add_comment' => 'Your workspace role cannot add comments.',
        });
    expect($task->fresh()->getAttributes())->toBe($before);
    expect(Task::count())->toBe(1);
    expect(Comment::count())->toBe(0);
})->with('ordinary write tools');

test('rejects foreign and missing write records without disclosing existence', function (string $tool, string $name, Closure $arguments, Closure $otherWorkspace) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $other = $otherWorkspace($user);
    $foreign = Task::factory()->for(Project::factory()->for($other))->create(['title' => 'Private foreign title']);
    $before = $foreign->fresh()->getAttributes();
    $token = $user->createToken('Record scope check')->plainTextToken;
    $foreignArguments = $arguments($foreign);
    $missingArguments = array_replace($foreignArguments, array_fill_keys(array_intersect(array_keys($foreignArguments), ['project_id', 'task_id']), 999999));

    $foreignResponse = $this->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $foreignArguments],
    ], ['Authorization' => 'Bearer '.$token]);
    Auth::forgetGuards();
    $missingResponse = $this->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $missingArguments],
    ], ['Authorization' => 'Bearer '.$token]);

    $foreignResponse->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'The record was not found in the current workspace.')
        ->assertDontSee('Private foreign title');
    $missingResponse->assertOk()
        ->assertJsonPath('result', $foreignResponse->json('result'));
    expect($foreign->fresh()->getAttributes())->toBe($before);
    expect(Task::count())->toBe(1);
    expect(Comment::count())->toBe(0);
})->with('ordinary write tools')->with([
    'non-member workspace' => [fn (User $user): Workspace => Workspace::factory()->create()],
    'accessible inactive workspace' => [fn (User $user): Workspace => Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create()],
]);

test('reports unexpected write failures without exposing internals or persisting changes', function (string $tool, string $name, Closure $arguments) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();
    $before = $task->fresh()->getAttributes();
    Exceptions::fake();
    Event::listen('eloquent.saving: '.($name === 'add_comment' ? Comment::class : Task::class), function (): void {
        throw new RuntimeException('SQLSTATE private task content /private/internal.php');
    });

    $response = taskWriteAs($user, $workspace, $tool, $arguments($task));

    $response->assertHasErrors(['Unable to complete this workspace operation. Please try again.'])
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('/private/internal.php');
    Exceptions::assertReported(RuntimeException::class);
    expect($task->fresh()->getAttributes())->toBe($before);
    expect(Task::count())->toBe(1);
    expect(Comment::count())->toBe(0);
})->with('ordinary write tools');

test('keeps web and MCP metadata validation equivalent', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();
    $before = $task->fresh()->getAttributes();

    $web = Livewire::actingAs($user)->test(TaskShow::class, ['taskId' => $task->id])
        ->call('startEditing')
        ->set('due_date', 'tomorrow')
        ->call('saveDetails');
    $mcp = taskWriteAs($user, $workspace, UpdateTaskTool::class, ['task_id' => $task->id, 'due_date' => 'tomorrow']);

    $web->assertHasErrors(['due_date' => 'date_format'])
        ->assertSee('The due date field must match the format Y-m-d.');
    $mcp->assertHasErrors(['The due date field must match the format Y-m-d.']);
    expect($task->fresh()->getAttributes())->toBe($before);
});

test('rejects missing and malformed update task IDs without mutations', function (array $input, string $message) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();
    $before = $task->fresh()->getAttributes();

    $response = taskWriteAs($user, $workspace, UpdateTaskTool::class, $input + ['title' => 'Rejected change']);

    $response->assertHasErrors([$message]);
    expect($task->fresh()->getAttributes())->toBe($before);
})->with([
    'missing' => [[], 'The task id field is required.'],
    'null' => [['task_id' => null], 'The task id field is required.'],
    'string' => [['task_id' => '1'], 'The task id field must be an integer.'],
    'non-positive' => [['task_id' => 0], 'The task id field must be at least 1.'],
]);

test('advertises metadata mutation schemas and accurate safety annotations', function (int $index, string $name, array $required) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $token = $user->createToken('Write schema client')->plainTextToken;

    $response = $this->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
    ], ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonCount(11, 'result.tools')
        ->assertJsonPath('result.tools.'.$index.'.name', $name)
        ->assertJsonPath('result.tools.'.$index.'.inputSchema.required', $required)
        ->assertJsonPath('result.tools.'.$index.'.inputSchema.additionalProperties', false)
        ->assertJsonPath('result.tools.'.$index.'.inputSchema.properties.title.maxLength', 255)
        ->assertJsonPath('result.tools.'.$index.'.inputSchema.properties.description.maxLength', 10000)
        ->assertJsonPath('result.tools.'.$index.'.inputSchema.properties.description.type', ['string', 'null'])
        ->assertJsonPath('result.tools.'.$index.'.inputSchema.properties.due_date.type', ['string', 'null'])
        ->assertJsonPath('result.tools.'.$index.'.inputSchema.properties.due_date.format', 'date')
        ->assertJsonPath('result.tools.'.$index.'.inputSchema.properties.priority.enum', ['low', 'medium', 'high'])
        ->assertJsonPath('result.tools.'.$index.'.outputSchema.additionalProperties', false)
        ->assertJsonPath('result.tools.'.$index.'.outputSchema.required', ['id', 'title', 'project', 'status', 'priority', 'assignee', 'due_date', 'description'])
        ->assertJsonPath('result.tools.'.$index.'.annotations.readOnlyHint', false)
        ->assertJsonPath('result.tools.'.$index.'.annotations.destructiveHint', false)
        ->assertJsonPath('result.tools.'.$index.'.annotations.idempotentHint', false)
        ->assertJsonPath('result.tools.'.$index.'.annotations.openWorldHint', false);
})->with([
    'creation' => [6, 'create_task', ['project_id', 'title']],
    'update' => [7, 'update_task', ['task_id']],
]);

test('publishes nullable creation assignment and medium default priority', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $token = $user->createToken('Creation schema client')->plainTextToken;

    $response = $this->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
    ], ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonPath('result.tools.6.inputSchema.properties.assignee_id.type', ['integer', 'null'])
        ->assertJsonPath('result.tools.6.inputSchema.properties.assignee_id.minimum', 1)
        ->assertJsonPath('result.tools.6.inputSchema.properties.priority.default', 'medium');
    expect(array_keys($response->json('result.tools.7.inputSchema.properties')))->toBe(['task_id', 'title', 'description', 'priority', 'due_date']);
});

test('creates and updates through bearer HTTP with matching structured and text results', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $other = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $project = Project::factory()->for($workspace)->create();
    $token = $user->createToken('Metadata workflow client')->plainTextToken;

    $created = $this->withSession([WorkspaceContext::SESSION_KEY => $other->id])->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'create_task', 'arguments' => ['project_id' => $project->id, 'title' => 'HTTP task']],
    ], ['Authorization' => 'Bearer '.$token]);

    $task = $project->tasks()->sole();
    $created->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.id', $task->id)
        ->assertJsonPath('result.structuredContent.title', 'HTTP task');
    expect(json_decode($created->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR))->toBe($created->json('result.structuredContent'));
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'project_id' => $project->id, 'title' => 'HTTP task', 'status' => 'todo', 'priority' => 'medium']);

    Auth::forgetGuards();
    $updated = $this->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
        'params' => ['name' => 'update_task', 'arguments' => ['task_id' => $task->id, 'description' => 'HTTP description', 'priority' => 'high']],
    ], ['Authorization' => 'Bearer '.$token]);

    $updated->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.description', 'HTTP description')
        ->assertJsonPath('result.structuredContent.priority', 'high');
    expect(json_decode($updated->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR))->toBe($updated->json('result.structuredContent'));
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'project_id' => $project->id, 'title' => 'HTTP task', 'description' => 'HTTP description', 'priority' => 'high', 'status' => 'todo']);
    expect(Task::count())->toBe(1);
    expect(Comment::count())->toBe(0);
});

test('assigns workspace members and unassigns tasks', function (WorkspaceRole $actorRole, ?WorkspaceRole $assigneeRole) {
    $user = User::factory()->create();
    $assignee = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, $actorRole)->withMember($assignee, $assigneeRole ?? WorkspaceRole::Member)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->assignedTo($user)->done()->create();
    $before = $task->fresh()->getAttributes();
    $assigneeId = $assigneeRole === null ? null : $assignee->id;

    $response = taskWriteAs($user, $workspace, AssignTaskTool::class, ['task_id' => $task->id, 'assignee_id' => $assigneeId]);

    $response->assertOk()->assertStructuredContent([
        'id' => $task->id, 'title' => $task->title, 'project' => ['id' => $task->project_id, 'name' => $task->project->name],
        'status' => 'done', 'priority' => $task->priority->value,
        'assignee' => $assigneeRole === null ? null : ['id' => $assignee->id, 'name' => $assignee->name],
        'due_date' => $task->due_date?->format('Y-m-d'),
    ]);
    $after = $task->fresh();
    expect($after->getAttributes())->toBe(array_replace($before, ['assignee_id' => $assigneeId, 'updated_at' => $after->getRawOriginal('updated_at')]));
})->with([WorkspaceRole::Owner, WorkspaceRole::Member])->with([
    'member' => [WorkspaceRole::Member], 'viewer' => [WorkspaceRole::Viewer], 'unassign' => [null],
]);

test('rejects foreign assignees without changing the task', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $foreign = User::factory()->create();
    Workspace::factory()->withMember($foreign, WorkspaceRole::Viewer)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->assignedTo($user)->create();
    $before = $task->fresh()->getAttributes();

    $response = taskWriteAs($user, $workspace, AssignTaskTool::class, ['task_id' => $task->id, 'assignee_id' => $foreign->id]);

    $response->assertHasErrors(['The assignee must be a member of the current workspace.']);
    expect($task->fresh()->getAttributes())->toBe($before);
});

test('rejects missing and malformed assignment arguments', function (array $input, string $message) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->assignedTo($user)->create();
    $before = $task->fresh()->getAttributes();

    $response = taskWriteAs($user, $workspace, AssignTaskTool::class, ['task_id' => $task->id] + $input);

    $response->assertHasErrors([$message]);
    expect($task->fresh()->getAttributes())->toBe($before);
})->with([
    'omitted assignee' => [[], 'The assignee id field must be present.'],
    'string ID' => [['assignee_id' => '1'], 'The assignee id field must be an integer.'],
    'zero ID' => [['assignee_id' => 0], 'The assignee id field must be at least 1.'],
    'missing member' => [['assignee_id' => 999999], 'The assignee must be a member of the current workspace.'],
]);

test('moves tasks between the allowed statuses', function (string $from, string $to, WorkspaceRole $role) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, $role)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->assignedTo($user)->create(['status' => $from]);
    $before = $task->fresh()->getAttributes();

    $response = taskWriteAs($user, $workspace, MoveTaskTool::class, ['task_id' => $task->id, 'status' => $to]);

    $response->assertOk()->assertStructuredContent([
        'id' => $task->id, 'title' => $task->title, 'project' => ['id' => $task->project_id, 'name' => $task->project->name],
        'status' => $to, 'priority' => $task->priority->value, 'assignee' => ['id' => $user->id, 'name' => $user->name],
        'due_date' => $task->due_date?->format('Y-m-d'),
    ]);
    $after = $task->fresh();
    expect($after->getAttributes())->toBe(array_replace($before, ['status' => $to, 'updated_at' => $after->getRawOriginal('updated_at')]));
})->with([
    'start' => ['todo', 'in_progress'], 'finish' => ['in_progress', 'done'], 'complete directly' => ['todo', 'done'],
    'return to todo' => ['in_progress', 'todo'], 'reopen' => ['done', 'todo'], 'resume' => ['done', 'in_progress'],
    'same status' => ['done', 'done'],
])->with([WorkspaceRole::Owner, WorkspaceRole::Member]);

test('rejects invalid status without changing the task', function (array $input) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();
    $before = $task->fresh()->getAttributes();

    $response = taskWriteAs($user, $workspace, MoveTaskTool::class, ['task_id' => $task->id] + $input);

    $response->assertHasErrors(['The status must be one of: todo, in_progress, done.']);
    expect($task->fresh()->getAttributes())->toBe($before);
})->with(['missing' => [[]], 'null' => [['status' => null]], 'unknown' => [['status' => 'blocked']]]);

test('adds a comment as the authenticated user', function (WorkspaceRole $role) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, $role)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();
    $before = $task->fresh()->getAttributes();

    $response = taskWriteAs($user, $workspace, AddCommentTool::class, ['task_id' => $task->id, 'body' => '  Acceptance criteria confirmed.  ']);

    $comment = $task->comments()->sole();
    $response->assertOk()->assertStructuredContent([
        'id' => $comment->id, 'task_id' => $task->id, 'body' => 'Acceptance criteria confirmed.',
        'author' => ['id' => $user->id, 'name' => $user->name],
    ]);
    $this->assertDatabaseHas('comments', ['id' => $comment->id, 'task_id' => $task->id, 'user_id' => $user->id, 'body' => 'Acceptance criteria confirmed.']);
    expect($task->fresh()->getAttributes())->toBe($before);
    expect(Comment::count())->toBe(1);
})->with([WorkspaceRole::Owner, WorkspaceRole::Member]);

test('rejects invalid comments without creating a record', function (array $input, string $message) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();
    $before = $task->fresh()->getAttributes();

    $response = taskWriteAs($user, $workspace, AddCommentTool::class, ['task_id' => $task->id] + $input);

    $response->assertHasErrors([$message]);
    expect(Comment::count())->toBe(0);
    expect($task->fresh()->getAttributes())->toBe($before);
})->with([
    'missing' => [[], 'The body field is required.'], 'blank' => [['body' => '   '], 'The body field is required.'],
    'oversized' => [['body' => str_repeat('a', 5001)], 'The body field must not be greater than 5000 characters.'],
    'non-string' => [['body' => 123], 'The body field must be a string.'],
    'forged author' => [['body' => 'Forged comment', 'user_id' => 999], 'The user_id field is not supported here.'],
]);

test('advertises ordinary mutation schemas and safety annotations', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $token = $user->createToken('Ordinary schema client')->plainTextToken;

    $response = $this->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
    ], ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()->assertJsonCount(11, 'result.tools');
    expect(array_column($response->json('result.tools'), 'name'))->toBe([
        'list_tasks', 'list_projects', 'list_my_tasks', 'get_task', 'search_tasks', 'list_workspace_members',
        'create_task', 'update_task', 'assign_task', 'move_task', 'add_comment',
    ]);
    foreach ([8 => ['assign_task', ['task_id', 'assignee_id']], 9 => ['move_task', ['task_id', 'status']], 10 => ['add_comment', ['task_id', 'body']]] as $index => [$name, $required]) {
        $response->assertJsonPath('result.tools.'.$index.'.name', $name)
            ->assertJsonPath('result.tools.'.$index.'.inputSchema.required', $required)
            ->assertJsonPath('result.tools.'.$index.'.inputSchema.additionalProperties', false)
            ->assertJsonPath('result.tools.'.$index.'.inputSchema.properties.task_id.minimum', 1)
            ->assertJsonPath('result.tools.'.$index.'.outputSchema.additionalProperties', false)
            ->assertJsonPath('result.tools.'.$index.'.annotations.readOnlyHint', false)
            ->assertJsonPath('result.tools.'.$index.'.annotations.destructiveHint', false)
            ->assertJsonPath('result.tools.'.$index.'.annotations.idempotentHint', false)
            ->assertJsonPath('result.tools.'.$index.'.annotations.openWorldHint', false);
    }
    $response->assertJsonPath('result.tools.8.inputSchema.properties.assignee_id.type', ['integer', 'null'])
        ->assertJsonPath('result.tools.8.inputSchema.properties.assignee_id.minimum', 1)
        ->assertJsonPath('result.tools.9.inputSchema.properties.status.enum', ['todo', 'in_progress', 'done'])
        ->assertJsonPath('result.tools.10.inputSchema.properties.body.minLength', 1)
        ->assertJsonPath('result.tools.10.inputSchema.properties.body.maxLength', 5000)
        ->assertJsonPath('result.tools.8.outputSchema.required', ['id', 'title', 'project', 'status', 'priority', 'assignee', 'due_date'])
        ->assertJsonPath('result.tools.9.outputSchema.required', ['id', 'title', 'project', 'status', 'priority', 'assignee', 'due_date'])
        ->assertJsonPath('result.tools.10.outputSchema.required', ['id', 'task_id', 'body', 'author'])
        ->assertJsonPath('result.tools.10.outputSchema.properties.author.additionalProperties', false)
        ->assertJsonPath('result.tools.10.outputSchema.properties.author.required', ['id', 'name']);
});

test('assigns moves and comments through bearer HTTP with matching structured and text results', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $other = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();
    $token = $user->createToken('Ordinary workflow client')->plainTextToken;

    foreach ([
        'assign_task' => ['assignee_id' => $user->id],
        'move_task' => ['status' => 'in_progress'],
        'add_comment' => ['body' => 'HTTP comment'],
    ] as $name => $arguments) {
        Auth::forgetGuards();
        $response = $this->withSession([WorkspaceContext::SESSION_KEY => $other->id])->postJson('/mcp/workspaces/'.$workspace->slug, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => ['task_id' => $task->id] + $arguments],
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertOk()->assertJsonPath('result.isError', false);
        expect(json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR))->toBe($response->json('result.structuredContent'));
    }
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'assignee_id' => $user->id, 'status' => 'in_progress']);
    $this->assertDatabaseHas('comments', ['task_id' => $task->id, 'user_id' => $user->id, 'body' => 'HTTP comment']);
    expect(Task::count())->toBe(1);
    expect(Comment::count())->toBe(1);
});

test('rejects malformed task IDs in ordinary writes without mutations', function (string $tool, array $arguments, mixed $taskId, string $message) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();
    $before = $task->fresh()->getAttributes();

    $response = taskWriteAs($user, $workspace, $tool, ['task_id' => $taskId] + $arguments);

    $response->assertHasErrors([$message]);
    expect($task->fresh()->getAttributes())->toBe($before);
    expect(Comment::count())->toBe(0);
})->with([
    'assign' => [AssignTaskTool::class, ['assignee_id' => null]],
    'move' => [MoveTaskTool::class, ['status' => 'done']],
    'comment' => [AddCommentTool::class, ['body' => 'Rejected comment']],
])->with([
    'null' => [null, 'The task id field is required.'],
    'string' => ['1', 'The task id field must be an integer.'],
    'zero' => [0, 'The task id field must be at least 1.'],
]);
