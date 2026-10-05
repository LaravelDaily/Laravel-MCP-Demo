<?php

use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;

$allRoles = [WorkspaceRole::Owner, WorkspaceRole::Member, WorkspaceRole::Viewer];

$matrix = [
    'view workspace' => ['view', 'workspace', $allRoles],
    'view project' => ['view', 'project', $allRoles],
    'view task' => ['view', 'task', $allRoles],
    'view comment' => ['view', 'comment', $allRoles],
    'create project' => ['create', 'new-project', [WorkspaceRole::Owner]],
    'update project' => ['update', 'project', [WorkspaceRole::Owner]],
    'delete project' => ['delete', 'project', [WorkspaceRole::Owner]],
    'create task' => ['create', 'new-task', [WorkspaceRole::Owner, WorkspaceRole::Member]],
    'update task' => ['update', 'task', [WorkspaceRole::Owner, WorkspaceRole::Member]],
    'assign task' => ['assign', 'task', [WorkspaceRole::Owner, WorkspaceRole::Member]],
    'move task' => ['move', 'task', [WorkspaceRole::Owner, WorkspaceRole::Member]],
    'delete task' => ['delete', 'task', [WorkspaceRole::Owner]],
    'create comment' => ['create', 'new-comment', [WorkspaceRole::Owner, WorkspaceRole::Member]],
    'contribute to workspace tasks' => ['contribute', 'workspace', [WorkspaceRole::Owner, WorkspaceRole::Member]],
    'delete workspace tasks' => ['deleteTasks', 'workspace', [WorkspaceRole::Owner]],
    'view MCP audit page' => ['viewMcpAudit', 'workspace', [WorkspaceRole::Owner]],
];

$cases = [];

foreach ($matrix as $label => [$ability, $argumentKind, $allowedRoles]) {
    foreach ([...$allRoles, null] as $role) {
        $cases["{$label} as ".($role?->value ?? 'non-member')] = [$ability, $argumentKind, $role, in_array($role, $allowedRoles, true)];
    }
}

test('enforces the workspace role permission matrix', function (string $ability, string $argumentKind, ?WorkspaceRole $role, bool $expected) {
    $workspace = Workspace::factory()->create();
    $project = Project::factory()->for($workspace)->create();
    $task = Task::factory()->for($project)->create();
    $comment = Comment::factory()->for($task)->for(User::factory()->create(), 'author')->create();

    $user = User::factory()->create();

    if ($role === null) {
        // The user is an owner elsewhere, so relationships owned by another
        // workspace must still be denied.
        Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    } else {
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    }

    $argument = match ($argumentKind) {
        'workspace' => $workspace,
        'project' => $project,
        'task' => $task,
        'comment' => $comment,
        'new-project' => [Project::class, $workspace],
        'new-task' => [Task::class, $project],
        'new-comment' => [Comment::class, $task],
    };

    expect($user->can($ability, $argument))->toBe($expected);
})->with($cases);
