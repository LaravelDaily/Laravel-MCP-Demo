<?php

use App\Enums\WorkspaceRole;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Show as ProjectShow;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceContext;
use Livewire\Livewire;

test('allows owners to create edit and delete projects', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->create();

    $this->actingAs($owner);

    Livewire::test(ProjectsIndex::class)
        ->set('name', '  Website Redesign  ')
        ->set('description', 'Refresh the marketing site')
        ->set('instructions', 'Require acceptance criteria.')
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::query()->where('workspace_id', $workspace->id)->firstOrFail();

    expect($project->name)->toBe('Website Redesign')
        ->and($project->description)->toBe('Refresh the marketing site')
        ->and($project->instructions)->toBe('Require acceptance criteria.');

    Livewire::test(ProjectsIndex::class)
        ->call('startEditing', $project->id)
        ->assertSet('editingId', $project->id)
        ->assertSet('name', 'Website Redesign')
        ->set('name', 'Website Relaunch')
        ->set('description', null)
        ->set('instructions', 'Require acceptance criteria and reproduction steps.')
        ->call('save')
        ->assertHasNoErrors();

    $project->refresh();

    expect($project->name)->toBe('Website Relaunch')
        ->and($project->description)->toBeNull()
        ->and($project->instructions)->toBe('Require acceptance criteria and reproduction steps.');

    $other = Project::factory()->for($workspace)->create(['name' => 'Keep me']);
    $task = Task::factory()->for($project)->create();
    Comment::factory()->for($task)->create();

    Livewire::test(ProjectsIndex::class)
        ->call('confirmDelete', $project->id)
        ->assertSet('deletingId', $project->id)
        ->call('delete')
        ->assertHasNoErrors();

    $this->assertModelMissing($project);
    $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    $this->assertDatabaseCount('comments', 0);
    $this->assertModelExists($other);
});

test('forbids project mutations by members and viewers', function (WorkspaceRole $role) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, $role)->create();
    $project = Project::factory()->for($workspace)->create(['name' => 'Unchanged']);

    $this->actingAs($user);

    Livewire::test(ProjectsIndex::class)
        ->set('name', 'Forged project')
        ->call('save')
        ->assertForbidden();

    Livewire::test(ProjectsIndex::class)
        ->set('editingId', $project->id)
        ->set('name', 'Forged rename')
        ->call('save')
        ->assertForbidden();

    Livewire::test(ProjectsIndex::class)
        ->set('deletingId', $project->id)
        ->call('delete')
        ->assertForbidden();

    expect($project->fresh()->name)->toBe('Unchanged');
    $this->assertDatabaseCount('projects', 1);
})->with([
    'member' => [WorkspaceRole::Member],
    'viewer' => [WorkspaceRole::Viewer],
]);

test('renders project and task details from the selected workspace', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();

    $acme = Workspace::factory()
        ->withMember($user, WorkspaceRole::Member)
        ->withMember($colleague, WorkspaceRole::Member)
        ->create(['name' => 'Acme']);
    $globex = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create(['name' => 'Globex']);

    $website = Project::factory()->for($acme)->create([
        'name' => 'Website Redesign',
        'description' => 'Refresh the marketing site',
        'instructions' => 'Require acceptance criteria.',
    ]);
    $task = Task::factory()->for($website)->assignedTo($colleague)->create([
        'title' => 'Redesign pricing page',
        'description' => 'New tiers need a new table.',
        'due_date' => '2026-11-01',
    ]);
    Comment::factory()->for($task)->for($colleague, 'author')->create(['body' => 'Pricing table approved.']);

    $foreign = Project::factory()->for($globex)->create(['name' => 'Globex Launch']);
    Task::factory()->for($foreign)->create(['title' => 'Migrate payroll data']);

    $this->actingAs($user);
    session([WorkspaceContext::SESSION_KEY => $acme->id]);

    $this->get(route('projects.show', ['projectId' => $website->id]))
        ->assertOk()
        ->assertSee('Website Redesign')
        ->assertSee('Refresh the marketing site')
        ->assertSee('Require acceptance criteria.')
        ->assertSee('Redesign pricing page')
        ->assertDontSee('Globex Launch')
        ->assertDontSee('Migrate payroll data');

    $this->get(route('tasks.show', ['taskId' => $task->id]))
        ->assertOk()
        ->assertSee('Redesign pricing page')
        ->assertSee('New tiers need a new table.')
        ->assertSee('Website Redesign')
        ->assertSee($colleague->name)
        ->assertSee('2026-11-01')
        ->assertSee('Pricing table approved.')
        ->assertDontSee('Globex Launch')
        ->assertDontSee('Migrate payroll data');
});

test('returns 404 for foreign project and task routes', function () {
    $user = User::factory()->create();

    $acme = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $globex = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();

    $this->actingAs($user);
    session([WorkspaceContext::SESSION_KEY => $acme->id]);

    $foreignProject = Project::factory()->for($globex)->create();
    $foreignTask = Task::factory()->for($foreignProject)->create();

    $this->get(route('projects.show', ['projectId' => $foreignProject->id]))->assertNotFound();
    $this->get(route('projects.show', ['projectId' => $foreignProject->id + 1000]))->assertNotFound();
    $this->get(route('tasks.show', ['taskId' => $foreignTask->id]))->assertNotFound();
    $this->get(route('tasks.show', ['taskId' => $foreignTask->id + 1000]))->assertNotFound();
});

test('paginates projects and project tasks within the component', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->create();

    Project::factory()->for($workspace)->count(11)
        ->sequence(fn ($sequence) => ['name' => sprintf('Listed project %02d', $sequence->index + 1)])
        ->create();
    $project = Project::query()->where('name', 'Listed project 01')->firstOrFail();

    Task::factory()->for($project)->count(11)
        ->sequence(fn ($sequence) => ['title' => sprintf('Listed task %02d', $sequence->index + 1)])
        ->create();

    $this->actingAs($owner);

    Livewire::test(ProjectsIndex::class)
        ->call('nextPage')
        ->assertSee('Listed project 11')
        ->assertDontSee('Listed project 10');

    Livewire::test(ProjectShow::class, ['projectId' => $project->id])
        ->call('gotoPage', 2)
        ->assertSee('Listed task 11')
        ->assertDontSee('Listed task 10');
});

test('shows an empty projects page for a user with no workspace', function () {
    $user = User::factory()->create();

    $foreign = Workspace::factory()->withMember(User::factory()->create(), WorkspaceRole::Owner)->create();
    Project::factory()->for($foreign)->create(['name' => 'Foreign Project']);

    $this->actingAs($user);

    $this->get(route('projects.index'))
        ->assertOk()
        ->assertSee('You are not a member of any workspace yet')
        ->assertDontSee('Foreign Project')
        ->assertDontSee('New project');

    $this->get(route('dashboard'))
        ->assertDontSee('href="'.route('projects.index').'"', false);
});
