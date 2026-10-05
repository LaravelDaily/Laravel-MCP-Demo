<?php

use App\Enums\WorkspaceRole;
use App\Mcp\Servers\TeamBoardServer;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListMyTasksTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\ListWorkspaceMembersTool;
use App\Mcp\Tools\SearchTasksTool;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\TestResponse;

function readToolAs(User $user, Workspace $workspace, string $tool, array $arguments = []): TestResponse
{
    $server = TeamBoardServer::actingAs($user);
    app(WorkspaceContext::class)->setExplicitWorkspace($workspace);

    return $server->tool($tool, $arguments);
}

dataset('read tools', [
    'list_tasks' => [ListTasksTool::class, fn (Task $task): array => []],
    'list_projects' => [ListProjectsTool::class, fn (Task $task): array => []],
    'list_my_tasks' => [ListMyTasksTool::class, fn (Task $task): array => []],
    'get_task' => [GetTaskTool::class, fn (Task $task): array => ['task_id' => $task->id]],
    'search_tasks' => [SearchTasksTool::class, fn (Task $task): array => ['query' => 'Readable']],
    'list_workspace_members' => [ListWorkspaceMembersTool::class, fn (Task $task): array => []],
]);

test('lists projects with unfinished task counts', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $other = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $project = Project::factory()->for($workspace)->create(['name' => 'Local project', 'description' => null]);
    Task::factory()->for($project)->todo()->create();
    Task::factory()->for($project)->inProgress()->create();
    Task::factory()->for($project)->done()->create();
    Task::factory()->for(Project::factory()->for($other))->create();
    Project::factory()->create(['name' => 'Foreign project']);

    $response = readToolAs($user, $workspace, ListProjectsTool::class);

    $response->assertOk()
        ->assertStructuredContent([
            'data' => [['id' => $project->id, 'name' => 'Local project', 'description' => null, 'open_task_count' => 2]],
            'meta' => ['page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1],
        ])
        ->assertDontSee('Foreign project');
});

test('searches project names and descriptions with scoped totals', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $first = Project::factory()->for($workspace)->create(['name' => 'MiXeD% title', 'description' => null]);
    $second = Project::factory()->for($workspace)->create(['name' => 'Description match', 'description' => 'mixed% text']);
    Project::factory()->for($workspace)->create(['name' => 'mixed unrelated', 'description' => null]);
    Project::factory()->create(['name' => 'mixed% foreign project']);

    $response = readToolAs($user, $workspace, ListProjectsTool::class, ['search' => '  MIXED%  ', 'per_page' => 1, 'page' => 2]);

    $response->assertOk()
        ->assertStructuredContent([
            'data' => [['id' => $second->id, 'name' => 'Description match', 'description' => 'mixed% text', 'open_task_count' => 0]],
            'meta' => ['page' => 2, 'per_page' => 1, 'total' => 2, 'last_page' => 2],
        ]);
    $this->assertModelExists($first);
});

test('lists only the authenticated users assigned tasks', function (array $filters, array $expected) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague, ['role' => WorkspaceRole::Viewer]);
    $other = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $project = Project::factory()->for($workspace)->create();
    $todo = Task::factory()->for($project)->assignedTo($user)->todo()->highPriority()->create();
    $done = Task::factory()->for($project)->assignedTo($user)->done()->create();
    Task::factory()->for($project)->assignedTo($colleague)->create(['title' => 'Another assignee task']);
    Task::factory()->for($project)->create(['title' => 'Unassigned task']);
    Task::factory()->for(Project::factory()->for($other))->assignedTo($user)->create(['title' => 'Inactive workspace assignment']);
    $ids = collect([$todo->id, $done->id])->only($expected)->values()->all();

    $response = readToolAs($user, $workspace, ListMyTasksTool::class, $filters);

    $response->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('data', fn (Collection $data) => $data->pluck('id')->all() === $ids)
            ->where('meta.total', count($ids))
            ->etc())
        ->assertDontSee(['Another assignee task', 'Unassigned task', 'Inactive workspace assignment']);
})->with([
    'all statuses by default' => [[], [0, 1]],
    'completed' => [['status' => 'done'], [1]],
    'high priority' => [['priority' => 'high'], [0]],
    'conjunctive' => [['status' => 'done', 'priority' => 'high'], []],
]);

test('returns task details with only the latest twenty comments', function () {
    $this->travelTo('2026-10-05 10:00:00');
    $user = User::factory()->create(['name' => 'Comment author']);
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $project = Project::factory()->for($workspace)->create(['name' => 'Detail project']);
    $task = Task::factory()->for($project)->assignedTo($user)->inProgress()->highPriority()->create(['title' => 'Detail task', 'description' => 'Task detail text', 'due_date' => '2026-10-12']);
    $comments = Comment::factory()->count(22)->for($task)->for($user, 'author')->sequence(fn ($sequence) => ['body' => 'Comment '.$sequence->index])->create();
    $comments->first()->forceFill(['created_at' => '2026-10-05 10:01:00'])->save();
    $expectedComments = collect([$comments[0]])->concat($comments->slice(3)->reverse())->map(fn (Comment $comment): array => [
        'id' => $comment->id,
        'body' => $comment->body,
        'author' => ['id' => $user->id, 'name' => 'Comment author'],
    ])->values()->all();

    $response = readToolAs($user, $workspace, GetTaskTool::class, ['task_id' => $task->id]);

    $response->assertOk()
        ->assertStructuredContent([
            'id' => $task->id,
            'title' => 'Detail task',
            'project' => ['id' => $project->id, 'name' => 'Detail project'],
            'status' => 'in_progress',
            'priority' => 'high',
            'assignee' => ['id' => $user->id, 'name' => 'Comment author'],
            'due_date' => '2026-10-12',
            'description' => 'Task detail text',
            'comments' => $expectedComments,
            'comments_total' => 22,
        ])
        ->assertDontSee($user->email);
});

test('returns nullable task details and empty comments', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();

    $response = readToolAs($user, $workspace, GetTaskTool::class, ['task_id' => $task->id]);

    $response->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('description', null)
            ->where('assignee', null)
            ->where('due_date', null)
            ->where('comments', [])
            ->where('comments_total', 0)
            ->etc());
});

test('rejects foreign and missing task IDs without disclosing existence', function (Closure $id) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $other = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $foreign = Task::factory()->create(['title' => 'Secret foreign task', 'description' => 'Secret description']);
    $inactive = Task::factory()->for(Project::factory()->for($other))->create(['title' => 'Inactive workspace task']);
    $token = $user->createToken('Task detail denial')->plainTextToken;

    $response = $this->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_task', 'arguments' => ['task_id' => $id($foreign, $inactive)]],
    ], ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content', [['type' => 'text', 'text' => 'The record was not found in the current workspace.']])
        ->assertJsonMissingPath('result.structuredContent')
        ->assertDontSee(['Secret foreign task', 'Secret description', 'Inactive workspace task']);
})->with([
    'foreign' => [fn (Task $foreign, Task $inactive): int => $foreign->id],
    'other accessible workspace' => [fn (Task $foreign, Task $inactive): int => $inactive->id],
    'missing' => [fn (Task $foreign, Task $inactive): int => 99999],
]);

test('searches task titles and descriptions within the selected workspace', function (array $filters, array $expected) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $project = Project::factory()->for($workspace)->create();
    $first = Task::factory()->for($project)->todo()->create(['title' => 'Needle title']);
    $second = Task::factory()->for($project)->done()->create(['title' => 'Description match', 'description' => 'nEeDlE text']);
    Task::factory()->for($project)->create(['title' => 'Unrelated']);
    Task::factory()->for(Project::factory()->for($workspace))->create(['title' => 'Needle outside project']);
    Task::factory()->create(['title' => 'Needle foreign']);
    $ids = collect([$first->id, $second->id])->only($expected)->values()->all();

    $response = readToolAs($user, $workspace, SearchTasksTool::class, ['query' => '  NEEDLE  ', 'project_id' => $project->id] + $filters);

    $response->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('data', fn (Collection $data) => $data->pluck('id')->all() === $ids)
            ->where('meta.total', count($ids))
            ->etc());
})->with([
    'title and description' => [[], [0, 1]],
    'status and project' => [['status' => 'done'], [1]],
]);

test('rejects missing blank and oversized search queries', function (array $arguments, string $message) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();

    $response = readToolAs($user, $workspace, SearchTasksTool::class, $arguments);

    $response->assertHasErrors([$message]);
})->with([
    'missing' => [[], 'query field is required'],
    'blank' => [['query' => '   '], 'query field is required'],
    'null' => [['query' => null], 'query field is required'],
    'oversized' => [['query' => str_repeat('a', 201)], '200'],
    'non-string' => [['query' => ['Needle']], 'string'],
]);

test('lists only current workspace members with public fields', function () {
    $user = User::factory()->create(['name' => 'Dana']);
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $other = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $member = User::factory()->create(['name' => 'Bob']);
    $workspace->members()->attach($member, ['role' => WorkspaceRole::Member]);
    $foreign = User::factory()->create(['name' => 'Foreign member']);
    Workspace::factory()->withMember($foreign, WorkspaceRole::Owner)->create();

    $response = readToolAs($user, $workspace, ListWorkspaceMembersTool::class);

    $response->assertOk()
        ->assertStructuredContent([
            'data' => [
                ['id' => $user->id, 'name' => 'Dana', 'role' => 'owner'],
                ['id' => $member->id, 'name' => 'Bob', 'role' => 'member'],
            ],
            'meta' => ['page' => 1, 'per_page' => 25, 'total' => 2, 'last_page' => 1],
        ])
        ->assertDontSee([$user->email, $member->email, 'Foreign member']);

    $response = readToolAs($user, $other, ListWorkspaceMembersTool::class);

    $response->assertOk()
        ->assertStructuredContent([
            'data' => [['id' => $user->id, 'name' => 'Dana', 'role' => 'viewer']],
            'meta' => ['page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1],
        ]);
});

test('searches member names only with literal case-insensitive text', function () {
    $user = User::factory()->create(['name' => 'ReAdEr_%']);
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $emailMatch = User::factory()->create(['name' => 'Unrelated', 'email' => 'reader@example.com']);
    $workspace->members()->attach($emailMatch, ['role' => WorkspaceRole::Member]);

    $response = readToolAs($user, $workspace, ListWorkspaceMembersTool::class, ['search' => '  reader_%  ']);

    $response->assertOk()
        ->assertStructuredContent([
            'data' => [['id' => $user->id, 'name' => 'ReAdEr_%', 'role' => 'viewer']],
            'meta' => ['page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1],
        ]);
});

test('allows owners members and viewers to use every read tool', function (string $tool, Closure $arguments, WorkspaceRole $role) {
    $user = User::factory()->create(['name' => 'Readable member']);
    $workspace = Workspace::factory()->withMember($user, $role)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace)->state(['name' => 'Readable project']))->assignedTo($user)->create(['title' => 'Readable task']);

    $response = readToolAs($user, $workspace, $tool, $arguments($task));

    $response->assertOk()
        ->assertSee('Readable');
})->with('read tools')->with(WorkspaceRole::cases());

test('rejects every unsupported read argument together', function (string $tool, Closure $arguments) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $task = Task::factory()->for(Project::factory()->for($workspace))->create();

    $response = readToolAs($user, $workspace, $tool, $arguments($task) + ['actor_id' => 1, 'workspace_id' => 2]);

    $response->assertHasErrors(['The actor_id field is not supported here.', 'The workspace_id field is not supported here.']);
})->with('read tools');

dataset('paginated read tools', [
    'list_projects' => [ListProjectsTool::class, [], fn (Collection $projects, Collection $tasks, Collection $members): array => $projects->pluck('id')->all()],
    'list_my_tasks' => [ListMyTasksTool::class, [], fn (Collection $projects, Collection $tasks, Collection $members): array => $tasks->pluck('id')->all()],
    'search_tasks' => [SearchTasksTool::class, ['query' => 'Paged'], fn (Collection $projects, Collection $tasks, Collection $members): array => $tasks->pluck('id')->all()],
    'list_workspace_members' => [ListWorkspaceMembersTool::class, [], fn (Collection $projects, Collection $tasks, Collection $members): array => $members->pluck('id')->all()],
]);

test('paginates every remaining read list with scoped totals', function (string $tool, array $arguments, Closure $expectedIds, array $pagination, int $page, int $perPage, int $lastPage) {
    $user = User::factory()->create(['name' => 'Paged member actor']);
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $members = User::factory()->count(26)->create(['name' => 'Paged colleague']);
    $workspace->members()->attach($members->modelKeys(), ['role' => WorkspaceRole::Member]);
    $projects = Project::factory()->count(27)->for($workspace)->create(['name' => 'Paged project']);
    $tasks = Task::factory()->count(27)->for($projects->first())->assignedTo($user)->create(['title' => 'Paged task']);
    Task::factory()->create(['title' => 'Paged foreign task']);
    $allIds = $expectedIds($projects, $tasks, collect([$user])->concat($members));
    $ids = array_slice($allIds, ($page - 1) * $perPage, $perPage);

    $response = readToolAs($user, $workspace, $tool, $arguments + $pagination);

    $response->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('data', fn (Collection $data) => $data->pluck('id')->all() === $ids)
            ->where('meta', ['page' => $page, 'per_page' => $perPage, 'total' => 27, 'last_page' => $lastPage]));
})->with('paginated read tools')->with([
    'defaults' => [[], 1, 25, 2],
    'second page' => [['page' => 2, 'per_page' => 10], 2, 10, 3],
    'maximum size' => [['per_page' => 100], 1, 100, 1],
    'past end' => [['page' => 9], 9, 25, 2],
]);

test('applies pagination validation to every remaining read list', function (string $tool, array $arguments, Closure $expectedIds) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();

    $response = readToolAs($user, $workspace, $tool, $arguments + ['per_page' => 101]);

    $response->assertHasErrors(['between 1 and 100']);
})->with('paginated read tools');

test('rejects caller-selected assignments in list_my_tasks', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();

    $response = readToolAs($user, $workspace, ListMyTasksTool::class, ['assignee_id' => $user->id]);

    $response->assertHasErrors(['The assignee_id field is not supported here.']);
});

test('rejects invalid get_task IDs', function (array $arguments, string $message) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();

    $response = readToolAs($user, $workspace, GetTaskTool::class, $arguments);

    $response->assertHasErrors([$message]);
})->with([
    'missing' => [[], 'task id field is required'],
    'null' => [['task_id' => null], 'task id field is required'],
    'zero' => [['task_id' => 0], 'at least 1'],
    'string' => [['task_id' => '1'], 'integer'],
    'pagination is not accepted' => [['task_id' => 1, 'page' => 2], 'The page field is not supported here.'],
]);

test('rejects a foreign project filter in search_tasks', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $foreign = Project::factory()->create(['name' => 'Foreign search project']);

    $response = readToolAs($user, $workspace, SearchTasksTool::class, ['query' => 'Needle', 'project_id' => $foreign->id]);

    $response->assertHasErrors(['The project must belong to the current workspace.'])
        ->assertDontSee('Foreign search project');
});

test('search_tasks treats wildcard characters as literal search text', function (string $query) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $project = Project::factory()->for($workspace)->create();
    $task = Task::factory()->for($project)->create(['title' => 'Literal '.$query.' match']);
    Task::factory()->for($project)->create(['title' => 'Unrelated task']);
    Task::factory()->create(['title' => 'Foreign '.$query.' match']);

    $response = readToolAs($user, $workspace, SearchTasksTool::class, ['query' => $query]);

    $response->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->count('data', 1)
            ->where('data.0.id', $task->id)
            ->where('meta.total', 1)
            ->etc());
})->with(['%', '_', '!']);

test('advertises the complete read surface with strict schemas and safety metadata', function (int $index, string $name, array $required, array $outputRequired) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $token = $user->createToken('Read discovery client')->plainTextToken;

    $response = $this->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
    ], ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonCount(6, 'result.tools')
        ->assertJsonPath('result.tools.'.$index.'.name', $name)
        ->assertJsonPath('result.tools.'.$index.'.inputSchema.additionalProperties', false)
        ->assertJsonPath('result.tools.'.$index.'.outputSchema.additionalProperties', false)
        ->assertJsonPath('result.tools.'.$index.'.outputSchema.required', $outputRequired)
        ->assertJsonPath('result.tools.'.$index.'.annotations.readOnlyHint', true)
        ->assertJsonPath('result.tools.'.$index.'.annotations.destructiveHint', false)
        ->assertJsonPath('result.tools.'.$index.'.annotations.openWorldHint', false);
    expect(($response->json('result.tools.'.$index.'.inputSchema.required') ?? []))->toBe($required);
})->with([
    'list_tasks' => [0, 'list_tasks', [], ['data', 'meta']],
    'list_projects' => [1, 'list_projects', [], ['data', 'meta']],
    'list_my_tasks' => [2, 'list_my_tasks', [], ['data', 'meta']],
    'get_task' => [3, 'get_task', ['task_id'], ['id', 'title', 'project', 'status', 'priority', 'assignee', 'due_date', 'description', 'comments', 'comments_total']],
    'search_tasks' => [4, 'search_tasks', ['query'], ['data', 'meta']],
    'list_workspace_members' => [5, 'list_workspace_members', [], ['data', 'meta']],
]);

test('keeps structured and fallback results equivalent without mutating domain data', function (string $tool, Closure $arguments) {
    $user = User::factory()->create(['name' => 'Readable member']);
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $project = Project::factory()->for($workspace)->create(['name' => 'Readable project']);
    $task = Task::factory()->for($project)->assignedTo($user)->create(['title' => 'Readable task']);
    $comment = Comment::factory()->for($task)->for($user, 'author')->create();
    $before = [$project->fresh()->getAttributes(), $task->fresh()->getAttributes(), $comment->fresh()->getAttributes()];
    $token = $user->createToken('Read transport client')->plainTextToken;
    $name = app($tool)->name();

    $response = $this->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => (object) $arguments($task)],
    ], ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonPath('result.isError', false);
    expect(json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR))
        ->toBe($response->json('result.structuredContent'));
    expect([$project->fresh()->getAttributes(), $task->fresh()->getAttributes(), $comment->fresh()->getAttributes()])->toBe($before);
    expect(Project::count())->toBe(1);
    expect(Task::count())->toBe(1);
    expect(Comment::count())->toBe(1);
})->with('read tools');

test('rejects blank and oversized optional read searches', function (string $tool, string $search, string $message) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();

    $response = readToolAs($user, $workspace, $tool, ['search' => $search]);

    $response->assertHasErrors([$message]);
})->with([ListProjectsTool::class, ListWorkspaceMembersTool::class])->with([
    'blank' => ['   ', 'search field is required'],
    'oversized' => [str_repeat('a', 201), '200'],
]);

test('does not disclose unexpected read failures even in debug mode', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    Task::factory()->for(Project::factory()->for($workspace))->create();
    config(['app.debug' => true]);
    Exceptions::fake();
    Event::listen('eloquent.retrieved: '.Task::class, function (): void {
        throw new RuntimeException('Sensitive SQL and filesystem detail');
    });

    $response = readToolAs($user, $workspace, ListTasksTool::class);

    $response->assertHasErrors(['Unable to read workspace data. Please try again.'])
        ->assertDontSee('Sensitive SQL and filesystem detail');
    Exceptions::assertReported(RuntimeException::class);
});

test('advertises bounded comment and query schemas', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    $token = $user->createToken('Bounded schema client')->plainTextToken;

    $response = $this->postJson('/mcp/workspaces/'.$workspace->slug, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
    ], ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonPath('result.tools.3.inputSchema.properties.task_id.minimum', 1)
        ->assertJsonPath('result.tools.3.outputSchema.properties.comments.maxItems', 20)
        ->assertJsonPath('result.tools.4.inputSchema.properties.query.minLength', 1)
        ->assertJsonPath('result.tools.4.inputSchema.properties.query.maxLength', 200)
        ->assertJsonPath('result.tools.5.outputSchema.properties.data.items.properties.role.enum', ['owner', 'member', 'viewer']);
});
