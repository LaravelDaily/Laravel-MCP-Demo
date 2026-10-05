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

test('creates tasks with todo status and medium priority by default', function () {
    $project = Project::factory()->create();

    $task = Task::query()->create([
        'project_id' => $project->id,
        'title' => 'Write the handover notes',
    ]);

    expect($task->fresh()->status)->toBe(TaskStatus::Todo)
        ->and($task->fresh()->priority)->toBe(TaskPriority::Medium)
        ->and($task->fresh()->assignee_id)->toBeNull()
        ->and($task->fresh()->due_date)->toBeNull();

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'status' => 'todo',
        'priority' => 'medium',
    ]);
});
