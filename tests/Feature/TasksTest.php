<?php

use App\Actions\Tasks\UpdateTaskDetails;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Livewire\Projects\Show as ProjectShow;
use App\Livewire\Tasks\Show as TaskShow;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function workspaceWithMember(User $user, WorkspaceRole $role): Workspace
{
    return Workspace::factory()->withMember($user, $role)->create();
}

test('creates and edits tasks for owners and members', function (WorkspaceRole $role) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, $role)->create();
    $colleague = User::factory()->create();
    $workspace->memberships()->create(['user_id' => $colleague->id, 'role' => WorkspaceRole::Viewer]);
    $project = Project::factory()->for($workspace)->create();

    $this->actingAs($user);

    Livewire::test(ProjectShow::class, ['projectId' => $project->id])
        ->set('title', '  Investigate failed webhook  ')
        ->set('description', 'Webhook retries are failing')
        ->set('assignee_id', (string) $colleague->id)
        ->set('due_date', '2026-11-15')
        ->set('priority', 'high')
        ->call('createTask')
        ->assertHasNoErrors();

    $task = Task::query()->where('project_id', $project->id)->firstOrFail();

    expect($task->title)->toBe('Investigate failed webhook')
        ->and($task->description)->toBe('Webhook retries are failing')
        ->and($task->status)->toBe(TaskStatus::Todo)
        ->and($task->priority)->toBe(TaskPriority::High)
        ->and($task->assignee_id)->toBe($colleague->id)
        ->and($task->due_date?->format('Y-m-d'))->toBe('2026-11-15');

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->call('startEditing')
        ->set('title', 'Investigate failed Stripe webhook')
        ->set('description', 'Retries fail after three attempts')
        ->set('priority', 'medium')
        ->set('due_date', '2026-12-01')
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->set('status', 'in_progress')
        ->call('changeStatus')
        ->assertHasNoErrors();

    $task->refresh();

    expect($task->title)->toBe('Investigate failed Stripe webhook')
        ->and($task->description)->toBe('Retries fail after three attempts')
        ->and($task->priority)->toBe(TaskPriority::Medium)
        ->and($task->due_date?->format('Y-m-d'))->toBe('2026-12-01')
        ->and($task->status)->toBe(TaskStatus::InProgress);
})->with([
    'owner' => [WorkspaceRole::Owner],
    'member' => [WorkspaceRole::Member],
]);

test('forbids viewer task mutations and comments', function () {
    $viewer = User::factory()->create();
    $workspace = workspaceWithMember($viewer, WorkspaceRole::Viewer);
    $project = Project::factory()->for($workspace)->create();
    $task = Task::factory()->for($project)->create(['title' => 'Unchanged']);

    $this->actingAs($viewer);

    Livewire::test(ProjectShow::class, ['projectId' => $project->id])
        ->set('title', 'Forged task')
        ->call('createTask')
        ->assertForbidden();

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->set('title', 'Forged rename')
        ->call('saveDetails')
        ->assertForbidden();

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->set('status', 'done')
        ->call('changeStatus')
        ->assertForbidden();

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->set('assignee_id', (string) $viewer->id)
        ->call('assign')
        ->assertForbidden();

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->set('body', 'Forged comment')
        ->call('addComment')
        ->assertForbidden();

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->call('delete')
        ->assertForbidden();

    expect($task->fresh()->title)->toBe('Unchanged')
        ->and($task->fresh()->status)->toBe(TaskStatus::Todo)
        ->and($task->fresh()->assignee_id)->toBeNull();

    $this->assertDatabaseCount('tasks', 1);
    $this->assertDatabaseCount('comments', 0);
});

test('allows task deletion only for owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $workspace = Workspace::factory()
        ->withMember($owner, WorkspaceRole::Owner)
        ->withMember($member, WorkspaceRole::Member)
        ->create();

    $project = Project::factory()->for($workspace)->create();
    $otherProject = Project::factory()->for($workspace)->create();

    $task = Task::factory()->for($project)->create();
    Comment::factory()->for($task)->create();
    $memberTask = Task::factory()->for($project)->create(['title' => 'Member target']);
    Comment::factory()->for($memberTask)->create();
    $otherTask = Task::factory()->for($otherProject)->create();

    $this->actingAs($owner);

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->call('delete')
        ->assertRedirect(route('projects.show', ['projectId' => $project->id]));

    $this->assertModelMissing($task);
    $this->assertDatabaseMissing('comments', ['task_id' => $task->id]);

    $this->actingAs($member);

    Livewire::test(TaskShow::class, ['taskId' => $memberTask->id])
        ->call('delete')
        ->assertForbidden();

    $this->assertModelExists($memberTask);
    $this->assertDatabaseHas('comments', ['task_id' => $memberTask->id]);
    $this->assertModelExists($otherTask);
});

test('rejects task creation and editing with invalid values', function (string $field, mixed $value) {
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, WorkspaceRole::Owner);
    $project = Project::factory()->for($workspace)->create();
    $task = Task::factory()->for($project)->create([
        'title' => 'Original title',
        'priority' => TaskPriority::Medium,
    ]);

    $this->actingAs($owner);

    Livewire::test(ProjectShow::class, ['projectId' => $project->id])
        ->set($field, $value)
        ->call('createTask')
        ->assertHasErrors($field);

    $this->assertDatabaseCount('tasks', 1);

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->set($field, $value)
        ->call('saveDetails')
        ->assertHasErrors($field);

    expect($task->fresh()->title)->toBe('Original title')
        ->and($task->fresh()->priority)->toBe(TaskPriority::Medium);
})->with([
    'blank title' => ['title', '   '],
    'oversized title' => ['title', str_repeat('a', 256)],
    'invalid priority' => ['priority', 'urgent'],
    'invalid due date' => ['due_date', '15-11-2026'],
    'oversized description' => ['description', str_repeat('a', 10001)],
]);

test('rejects an assignee outside the current workspace', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, WorkspaceRole::Owner);
    $project = Project::factory()->for($workspace)->create();

    $colleague = User::factory()->create();
    $workspace->memberships()->create(['user_id' => $colleague->id, 'role' => WorkspaceRole::Member]);
    $task = Task::factory()->for($project)->assignedTo($colleague)->create();

    $foreignUser = User::factory()->create();
    $foreignWorkspace = Workspace::factory()->withMember($foreignUser, WorkspaceRole::Owner)->create();

    $this->actingAs($owner);

    Livewire::test(ProjectShow::class, ['projectId' => $project->id])
        ->set('title', 'Valid title')
        ->set('assignee_id', (string) $foreignUser->id)
        ->call('createTask')
        ->assertHasErrors(['assignee_id' => 'The assignee must be a member of the current workspace.']);

    $this->assertDatabaseCount('tasks', 1);

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->set('assignee_id', (string) $foreignUser->id)
        ->call('assign')
        ->assertHasErrors(['assignee_id' => 'The assignee must be a member of the current workspace.']);

    expect($task->fresh()->assignee_id)->toBe($colleague->id);
});

test('allows assignment to a viewer and explicit unassignment', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, WorkspaceRole::Owner);
    $project = Project::factory()->for($workspace)->create();
    $task = Task::factory()->for($project)->create();

    $viewer = User::factory()->create();
    $workspace->memberships()->create(['user_id' => $viewer->id, 'role' => WorkspaceRole::Viewer]);

    $this->actingAs($owner);

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->set('assignee_id', (string) $viewer->id)
        ->call('assign')
        ->assertHasNoErrors();

    expect($task->fresh()->assignee_id)->toBe($viewer->id);

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->set('assignee_id', '')
        ->call('assign')
        ->assertHasNoErrors();

    expect($task->fresh()->assignee_id)->toBeNull();
});

test('preserves omitted metadata and clears explicit nullable fields', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, WorkspaceRole::Owner);
    $project = Project::factory()->for($workspace)->create();

    $task = Task::factory()->for($project)->create([
        'title' => 'Original title',
        'description' => 'Original description',
        'priority' => TaskPriority::Medium,
        'due_date' => '2026-10-20',
    ]);

    $this->actingAs($owner);

    app(UpdateTaskDetails::class)($owner, $task, ['title' => 'Renamed title']);

    $task->refresh();

    expect($task->title)->toBe('Renamed title')
        ->and($task->description)->toBe('Original description')
        ->and($task->priority)->toBe(TaskPriority::Medium)
        ->and($task->due_date?->format('Y-m-d'))->toBe('2026-10-20');

    app(UpdateTaskDetails::class)($owner, $task, ['description' => null, 'due_date' => null]);

    $task->refresh();

    expect($task->title)->toBe('Renamed title')
        ->and($task->description)->toBeNull()
        ->and($task->due_date)->toBeNull();

    expect(fn () => app(UpdateTaskDetails::class)($owner, $task, []))
        ->toThrow(ValidationException::class, 'At least one task field is required.');

    expect(fn () => app(UpdateTaskDetails::class)($owner, $task, ['title' => null]))
        ->toThrow(ValidationException::class);

    expect(fn () => app(UpdateTaskDetails::class)($owner, $task, ['priority' => null]))
        ->toThrow(ValidationException::class);

    $task->refresh();

    expect($task->title)->toBe('Renamed title')
        ->and($task->description)->toBeNull()
        ->and($task->due_date)->toBeNull()
        ->and($task->priority)->toBe(TaskPriority::Medium);
});

test('creates comments with the authenticated author', function () {
    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, WorkspaceRole::Member);
    $project = Project::factory()->for($workspace)->create();
    $task = Task::factory()->for($project)->create();

    $otherUser = User::factory()->create();
    $workspace->memberships()->create(['user_id' => $otherUser->id, 'role' => WorkspaceRole::Member]);

    $this->actingAs($author);

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->set('body', '  Reproduced on staging  ')
        ->call('addComment', ['user_id' => $otherUser->id])
        ->assertHasNoErrors();

    $comment = Comment::query()->where('task_id', $task->id)->firstOrFail();

    expect($comment->body)->toBe('Reproduced on staging')
        ->and($comment->user_id)->toBe($author->id);

    $this->assertDatabaseCount('comments', 1);
});

test('rejects blank and oversized comments', function (string $body) {
    $member = User::factory()->create();
    $workspace = workspaceWithMember($member, WorkspaceRole::Member);
    $project = Project::factory()->for($workspace)->create();
    $task = Task::factory()->for($project)->create();

    $this->actingAs($member);

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->set('body', $body)
        ->call('addComment')
        ->assertHasErrors('body');

    $this->assertDatabaseCount('comments', 0);
})->with([
    'blank body' => ['   '],
    'oversized body' => [str_repeat('a', 5001)],
]);

test('rejects forged record IDs during Livewire mutations', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, WorkspaceRole::Owner);

    $foreignOwner = User::factory()->create();
    $foreignWorkspace = Workspace::factory()->withMember($foreignOwner, WorkspaceRole::Owner)->create();

    $project = Project::factory()->for($workspace)->create();
    $task = Task::factory()->for($project)->create(['title' => 'Own task']);

    $foreignProject = Project::factory()->for($foreignWorkspace)->create();
    $foreignTask = Task::factory()->for($foreignProject)->create(['title' => 'Foreign task']);

    $this->actingAs($owner);

    // A forged Livewire payload sends the record ID and the action call in
    // one request; each mutation below submits a foreign ID that way.
    $call = fn (string $method) => [['method' => $method, 'params' => [], 'path' => '']];

    Livewire::test(ProjectShow::class, ['projectId' => $project->id])
        ->update(
            calls: $call('createTask'),
            updates: ['title' => 'Forged task', 'projectId' => $foreignProject->id],
        )
        ->assertNotFound();

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->update($call('saveDetails'), ['title' => 'Forged rename', 'taskId' => $foreignTask->id])
        ->assertNotFound();

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->update($call('changeStatus'), ['status' => 'done', 'taskId' => $foreignTask->id])
        ->assertNotFound();

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->update($call('assign'), ['assignee_id' => (string) $owner->id, 'taskId' => $foreignTask->id])
        ->assertNotFound();

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->update($call('addComment'), ['body' => 'Forged comment', 'taskId' => $foreignTask->id])
        ->assertNotFound();

    Livewire::test(TaskShow::class, ['taskId' => $task->id])
        ->update($call('delete'), ['taskId' => $foreignTask->id])
        ->assertNotFound();

    expect($foreignTask->fresh()->title)->toBe('Foreign task')
        ->and($foreignTask->fresh()->status)->toBe(TaskStatus::Todo)
        ->and($task->fresh()->title)->toBe('Own task');

    $this->assertDatabaseCount('tasks', 2);
    $this->assertDatabaseCount('comments', 0);
});

test('rechecks permission when membership changes after a component mounts', function () {
    $member = User::factory()->create();
    $workspace = workspaceWithMember($member, WorkspaceRole::Member);
    $project = Project::factory()->for($workspace)->create();
    $task = Task::factory()->for($project)->create(['title' => 'Unchanged']);

    $this->actingAs($member);

    // Each Livewire interaction is its own request; the membership change
    // below happens between the mount request and the action request, so
    // passing here proves the action re-authorized from fresh state.
    $component = Livewire::test(TaskShow::class, ['taskId' => $task->id]);
    $projectComponent = Livewire::test(ProjectShow::class, ['projectId' => $project->id]);

    $workspace->members()->detach($member);

    $component->call('saveDetails')->assertNotFound();
    $projectComponent->call('createTask')->assertNotFound();

    expect($task->fresh()->title)->toBe('Unchanged');
    $this->assertDatabaseCount('tasks', 1);

    $downgraded = User::factory()->create();
    $otherWorkspace = workspaceWithMember($downgraded, WorkspaceRole::Member);
    $otherProject = Project::factory()->for($otherWorkspace)->create();
    $otherTask = Task::factory()->for($otherProject)->create(['title' => 'Still unchanged']);

    $this->actingAs($downgraded);

    $downgradedComponent = Livewire::test(TaskShow::class, ['taskId' => $otherTask->id]);

    $otherWorkspace->memberships()
        ->where('user_id', $downgraded->id)
        ->firstOrFail()
        ->update(['role' => WorkspaceRole::Viewer]);

    $downgradedComponent->set('title', 'Downgraded rename')->call('saveDetails')->assertForbidden();

    expect($otherTask->fresh()->title)->toBe('Still unchanged');
});
