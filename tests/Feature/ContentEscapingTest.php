<?php

use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceContext;

test('escapes user provided project task and comment content', function () {
    $payload = '<script>teamboard-xss()</script>';

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->create();
    $project = Project::factory()->for($workspace)->create([
        'name' => "Project {$payload}",
        'description' => "Description {$payload}",
        'instructions' => "Instructions {$payload}",
    ]);
    $task = Task::factory()->for($project)->create([
        'title' => "Task {$payload}",
        'description' => "Task description {$payload}",
    ]);
    Comment::factory()->for($task)->for($owner, 'author')->create([
        'body' => "Comment {$payload}",
    ]);

    $this->actingAs($owner);
    session([WorkspaceContext::SESSION_KEY => $workspace->id]);

    $projectPage = $this->get(route('projects.show', ['projectId' => $project->id]))->assertOk();
    $taskPage = $this->get(route('tasks.show', ['taskId' => $task->id]))->assertOk();

    foreach ([$projectPage, $taskPage] as $page) {
        expect($page->getContent())
            ->toContain('&lt;script&gt;teamboard-xss()')
            ->not->toContain('<script>teamboard-xss()');
    }

    expect($projectPage->getContent())
        ->toContain('Instructions &lt;script&gt;teamboard-xss()');

    expect($taskPage->getContent())
        ->toContain('Task &lt;script&gt;teamboard-xss()')
        ->toContain('Comment &lt;script&gt;teamboard-xss()');
});
