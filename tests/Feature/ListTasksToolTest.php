<?php

use App\Enums\WorkspaceRole;
use App\Mcp\Servers\TeamBoardServer;
use App\Mcp\Tools\ListTasksTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\TestResponse;

function listTasksAs(User $user, Workspace $workspace, array $arguments = []): TestResponse
{
    $server = TeamBoardServer::actingAs($user);
    app(WorkspaceContext::class)->setExplicitWorkspace($workspace);

    return $server->tool(ListTasksTool::class, $arguments);
}

test('lists only tasks from the selected workspace', function () {
    $user = User::factory()->create(['name' => 'Reader']);
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $other = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $project = Project::factory()->for($workspace)->create(['name' => 'Local project']);
    $first = Task::factory()->for($project)->inProgress()->highPriority()->assignedTo($user)->create(['title' => 'Visible task', 'description' => 'Private detail', 'due_date' => '2026-10-12']);
    $second = Task::factory()->for($project)->create(['title' => 'Unassigned']);
    Task::factory()->for(Project::factory()->for($other))->create(['title' => 'Other accessible task']);
    Task::factory()->create(['title' => 'Foreign task']);

    $response = listTasksAs($user, $workspace);

    $response->assertOk()
        ->assertStructuredContent([
            'data' => [
                ['id' => $first->id, 'title' => 'Visible task', 'project' => ['id' => $project->id, 'name' => 'Local project'], 'status' => 'in_progress', 'priority' => 'high', 'assignee' => ['id' => $user->id, 'name' => 'Reader'], 'due_date' => '2026-10-12'],
                ['id' => $second->id, 'title' => 'Unassigned', 'project' => ['id' => $project->id, 'name' => 'Local project'], 'status' => 'todo', 'priority' => 'medium', 'assignee' => null, 'due_date' => null],
            ],
            'meta' => ['page' => 1, 'per_page' => 25, 'total' => 2, 'last_page' => 1],
        ])
        ->assertDontSee(['Foreign task', 'Other accessible task', 'Private detail', $user->email]);
});

test('combines task filters without widening tenant scope', function (Closure $filters, array $expected) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $project = Project::factory()->for($workspace)->create();
    $first = Task::factory()->for($project)->inProgress()->highPriority()->assignedTo($user)->create(['title' => 'Needle first']);
    $second = Task::factory()->for($project)->todo()->create(['title' => 'Second', 'description' => 'Needle description']);
    Task::factory()->for($project)->done()->create(['title' => 'Unrelated']);
    Task::factory()->for(Project::factory()->for($workspace))->inProgress()->highPriority()->create(['title' => 'Needle outside project']);
    Task::factory()->create(['title' => 'Needle foreign']);
    $arguments = ['project_id' => $project->id] + $filters($user);
    $ids = array_map(fn (int $index) => [$first->id, $second->id][$index], $expected);

    $response = listTasksAs($user, $workspace, $arguments);

    $response->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('data', fn (Collection $data) => $data->pluck('id')->all() === $ids)
            ->where('meta.total', count($ids))
            ->etc());
})->with([
    'project and search' => [fn (User $user): array => ['search' => '  Needle  '], [0, 1]],
    'status' => [fn (User $user): array => ['status' => 'in_progress'], [0]],
    'priority' => [fn (User $user): array => ['priority' => 'high'], [0]],
    'assignee' => [fn (User $user): array => ['assignee_id' => $user->id], [0]],
    'all filters' => [fn (User $user): array => ['status' => 'in_progress', 'priority' => 'high', 'assignee_id' => $user->id, 'search' => 'Needle'], [0]],
    'incompatible filters' => [fn (User $user): array => ['status' => 'todo', 'priority' => 'high'], []],
]);

test('rejects invalid and foreign read filters', function (array $arguments, string $message) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    Task::factory()->for(Project::factory()->for($workspace))->create(['title' => 'Visible only on success']);

    $response = listTasksAs($user, $workspace, $arguments);

    $response->assertHasErrors([$message])
        ->assertDontSee('Visible only on success');
})->with([
    'invalid status' => [['status' => 'blocked'], 'The status must be one of: todo, in_progress, done.'],
    'invalid priority' => [['priority' => 'urgent'], 'The priority must be one of: low, medium, high.'],
    'zero ID' => [['project_id' => 0], 'project id'],
    'null ID' => [['assignee_id' => null], 'assignee id'],
    'string ID' => [['project_id' => '1'], 'integer'],
    'missing project' => [['project_id' => 99999], 'project must belong'],
    'missing member' => [['assignee_id' => 99999], 'assignee must be a member'],
    'blank search' => [['search' => '   '], 'search field is required'],
    'oversized search' => [['search' => str_repeat('a', 201)], '200'],
    'non-string search' => [['search' => ['x']], 'string'],
]);

test('rejects filters in foreign and other accessible workspaces', function (Closure $filters) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $other = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $foreignUser = User::factory()->create();
    $foreign = Workspace::factory()->withMember($foreignUser, WorkspaceRole::Owner)->create();
    $project = Project::factory()->for($foreign)->create(['name' => 'Secret project']);
    $otherProject = Project::factory()->for($other)->create(['name' => 'Other accessible project']);

    $response = listTasksAs($user, $workspace, $filters($project, $otherProject, $foreignUser));

    $response->assertHasErrors(['current workspace'])
        ->assertDontSee(['Secret project', $foreignUser->name]);
})->with([
    'foreign project' => [fn (Project $foreign, Project $other, User $assignee): array => ['project_id' => $foreign->id]],
    'accessible project' => [fn (Project $foreign, Project $other, User $assignee): array => ['project_id' => $other->id]],
    'foreign assignee' => [fn (Project $foreign, Project $other, User $assignee): array => ['assignee_id' => $assignee->id]],
]);

test('paginates read results with scoped totals and bounded page sizes', function (array $arguments, int $count, int $page, int $perPage, int $lastPage) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $tasks = Task::factory()
        ->count(27)->for(Project::factory()->for($workspace))->create();
    Task::factory()->create();
    $ids = $tasks->pluck('id')->slice(($page - 1) * $perPage, $perPage)->values()->all();

    $response = listTasksAs($user, $workspace, $arguments);

    $response->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->count('data', $count)
            ->where('data', fn (Collection $data) => $data->pluck('id')->all() === $ids)
            ->where('meta', ['page' => $page, 'per_page' => $perPage, 'total' => 27, 'last_page' => $lastPage]));
})->with([
    'defaults' => [[], 25, 1, 25, 2],
    'second page' => [['page' => 2, 'per_page' => 10], 10, 2, 10, 3],
    'last page' => [['page' => 2], 2, 2, 25, 2],
    'maximum page size' => [['per_page' => 100], 27, 1, 100, 1],
    'past end' => [['page' => 9], 0, 9, 25, 2],
]);

test('rejects invalid pagination bounds', function (array $arguments) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();

    $response = listTasksAs($user, $workspace, $arguments);

    $response->assertHasErrors();
})->with([
    'page zero' => [['page' => 0]],
    'page negative' => [['page' => -1]],
    'page fractional' => [['page' => 1.5]],
    'page string' => [['page' => '1']],
    'per page zero' => [['per_page' => 0]],
    'per page too large' => [['per_page' => 101]],
    'per page null' => [['per_page' => null]],
]);

test('treats wildcard characters as literal search text', function (string $search) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $project = Project::factory()->for($workspace)->create();
    $match = Task::factory()->for($project)->create(['title' => 'Literal '.$search.' match']);
    Task::factory()->for($project)->create(['title' => 'Unrelated task']);
    Task::factory()->create(['title' => 'Foreign '.$search.' match']);

    $response = listTasksAs($user, $workspace, ['search' => $search]);

    $response->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->count('data', 1)
            ->where('data.0.id', $match->id)
            ->where('meta.total', 1)
            ->etc());
})->with(['%', '_', '!', "' OR 1=1 --"]);

test('allows owners members and viewers to use read tools', function (WorkspaceRole $role) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, $role)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();

    $response = listTasksAs($user, $workspace);

    $response->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->count('data', 1)
            ->where('data.0.id', $task->id)
            ->etc());
})->with(WorkspaceRole::cases());

test('rejects unsupported read arguments', function (string $argument) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();

    $response = listTasksAs($user, $workspace, [$argument => 1]);

    $response->assertHasErrors(['The '.$argument.' field is not supported here.']);
})->with(['actor_id', 'workspace_id', 'sort', 'description']);

test('advertises read tool names schemas and safety metadata', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $token = $user->createToken('Discovery')->plainTextToken;

    $response = $this->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ], ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonCount(6, 'result.tools')
        ->assertJsonPath('result.tools.0.name', 'list_tasks')
        ->assertJsonPath('result.tools.0.inputSchema.additionalProperties', false)
        ->assertJsonPath('result.tools.0.inputSchema.properties.page.minimum', 1)
        ->assertJsonPath('result.tools.0.inputSchema.properties.per_page.maximum', 100)
        ->assertJsonPath('result.tools.0.inputSchema.properties.status.enum', ['todo', 'in_progress', 'done'])
        ->assertJsonPath('result.tools.0.inputSchema.properties.priority.enum', ['low', 'medium', 'high'])
        ->assertJsonPath('result.tools.0.outputSchema.required', ['data', 'meta'])
        ->assertJsonPath('result.tools.0.annotations.readOnlyHint', true)
        ->assertJsonPath('result.tools.0.annotations.destructiveHint', false);
});

test('returns structured task results and a matching JSON text fallback over bearer HTTP', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $other = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create(['title' => 'Token reader task']);
    Task::factory()->for(Project::factory()->for($other))->create(['title' => 'Wrong workspace']);
    $token = $user->createToken('Client')->plainTextToken;

    $response = $this->withSession([WorkspaceContext::SESSION_KEY => $other->id])
        ->postJson('/mcp/workspaces/'.$workspace->slug, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'list_tasks', 'arguments' => (object) []],
        ], ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonCount(1, 'result.structuredContent.data')
        ->assertJsonPath('result.structuredContent.data.0.id', $task->id)
        ->assertDontSee('Wrong workspace');
    expect(json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR))->toBe($response->json('result.structuredContent'));

    Auth::forgetGuards();
    $response = $this->postJson('/mcp/workspaces/'.$other->slug, [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'list_tasks', 'arguments' => (object) []],
    ], ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonPath('result.structuredContent.data.0.title', 'Wrong workspace')
        ->assertDontSee('Token reader task');
});

test('reports every unsupported argument in one response', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();

    $response = listTasksAs($user, $workspace, ['actor_id' => 1, 'workspace_id' => 2, 'sort' => 'name']);

    $response->assertHasErrors([
        'The actor_id field is not supported here.',
        'The workspace_id field is not supported here.',
        'The sort field is not supported here.',
    ]);
});

test('searches task text case-insensitively', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $project = Project::factory()->for($workspace)->create();
    Task::factory()->for($project)->create(['title' => 'MiXeD Title']);
    Task::factory()->for($project)->create(['title' => 'Description match', 'description' => 'mIxEd description']);
    Task::factory()->create(['title' => 'MIXED foreign task']);

    $response = listTasksAs($user, $workspace, ['search' => 'MIXED']);

    $response->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->count('data', 2)
            ->where('meta.total', 2)
            ->etc())
        ->assertDontSee('MIXED foreign task');
});
