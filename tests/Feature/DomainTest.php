<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;

test('allows a user to belong to multiple workspaces with different roles', function () {
    $dana = User::factory()->create();

    $acme = Workspace::factory()->withMember($dana, WorkspaceRole::Owner)->create();
    $sandbox = Workspace::factory()->withMember($dana, WorkspaceRole::Viewer)->create();

    expect($dana->roleIn($acme))->toBe(WorkspaceRole::Owner)
        ->and($dana->roleIn($sandbox))->toBe(WorkspaceRole::Viewer)
        ->and($dana->workspaces->pluck('id')->all())->toBe([$acme->id, $sandbox->id])
        ->and($acme->members->pluck('id')->all())->toBe([$dana->id]);
});

test('updates and deletes a membership loaded through its workspace', function () {
    $user = User::factory()->create();
    $otherMember = User::factory()->create();

    $workspace = Workspace::factory()
        ->withMember($user, WorkspaceRole::Member)
        ->withMember($otherMember, WorkspaceRole::Member)
        ->create();

    $workspace->memberships()->where('user_id', $user->id)->firstOrFail()->update(['role' => WorkspaceRole::Viewer]);

    expect($user->roleIn($workspace))->toBe(WorkspaceRole::Viewer)
        ->and($otherMember->roleIn($workspace))->toBe(WorkspaceRole::Member);

    $workspace->memberships()->where('user_id', $user->id)->firstOrFail()->delete();

    expect($user->roleIn($workspace))->toBeNull()
        ->and($otherMember->roleIn($workspace))->toBe(WorkspaceRole::Member);
});

test('resolves project tasks and comment authors through their relationships', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $acme = Workspace::factory()->withMember($alice, WorkspaceRole::Owner)->withMember($bob, WorkspaceRole::Member)->create();

    $project = Project::factory()->for($acme)->create();
    $task = Task::factory()->for($project)->assignedTo($bob)->create();
    $unassigned = Task::factory()->for($project)->create();
    $comment = Comment::factory()->for($task)->for($alice, 'author')->create();

    expect($project->workspace->is($acme))->toBeTrue()
        ->and($task->project->is($project))->toBeTrue()
        ->and($task->assignee->is($bob))->toBeTrue()
        ->and($unassigned->assignee)->toBeNull()
        ->and($comment->task->is($task))->toBeTrue()
        ->and($comment->author->is($alice))->toBeTrue();
});

test('creates comment authors as members of the task workspace by default', function () {
    $task = Task::factory()->create();

    $comment = Comment::factory()->for($task)->create();

    expect($comment->author->roleIn($task->project->workspace_id))->toBe(WorkspaceRole::Member);
});

test('creates tasks with todo status and medium priority by default', function () {
    $project = Project::factory()->create();

    $task = Task::query()->create([
        'project_id' => $project->id,
        'title' => 'Write the handover notes',
    ]);

    expect($task->status)->toBe(TaskStatus::Todo)
        ->and($task->priority)->toBe(TaskPriority::Medium);

    $persisted = $task->fresh();

    expect($persisted->status)->toBe(TaskStatus::Todo)
        ->and($persisted->priority)->toBe(TaskPriority::Medium)
        ->and($persisted->assignee_id)->toBeNull()
        ->and($persisted->due_date)->toBeNull();

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'status' => 'todo',
        'priority' => 'medium',
    ]);
});
