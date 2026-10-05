<?php

use App\Enums\WorkspaceRole;
use App\Livewire\WorkspaceSwitcher;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceContext;
use Livewire\Livewire;

test('redirects guests to login for TeamBoard pages', function (string $routeName, array $parameters) {
    $this->get(route($routeName, $parameters))->assertRedirect(route('login'));
})->with([
    'dashboard' => ['dashboard', []],
    'projects index' => ['projects.index', []],
    'project detail' => ['projects.show', ['projectId' => 1]],
]);

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();
});

test('shows an empty dashboard for a user with no workspace', function () {
    $user = User::factory()->create();

    $foreign = Workspace::factory()->withMember(User::factory()->create(), WorkspaceRole::Owner)->create();
    Project::factory()->for($foreign)->create(['name' => 'Foreign Project']);

    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('You are not a member of any workspace yet')
        ->assertDontSee('open task')
        ->assertDontSee('Foreign Project');
});

test('switches workspace and renders only its dashboard data', function () {
    $user = User::factory()->create();

    $acme = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create(['name' => 'Acme']);
    $globex = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create(['name' => 'Globex']);

    $website = Project::factory()->for($acme)->create(['name' => 'Website Redesign']);
    Task::factory()->for($website)->assignedTo($user)->create(['title' => 'Acme open task']);
    Task::factory()->for($website)->assignedTo($user)->done()->create(['title' => 'Acme done task']);

    $launch = Project::factory()->for($globex)->create(['name' => 'Globex Launch']);
    Task::factory()->for($launch)->assignedTo($user)->inProgress()->create(['title' => 'Globex assigned task']);

    $this->actingAs($user);
    session([WorkspaceContext::SESSION_KEY => $acme->id]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Website Redesign')
        ->assertSee('Acme open task')
        ->assertDontSee('Globex Launch')
        ->assertDontSee('Globex assigned task');

    Livewire::test(WorkspaceSwitcher::class)
        ->call('switchTo', $globex->id)
        ->assertRedirect(route('dashboard'));

    expect(session(WorkspaceContext::SESSION_KEY))->toBe($globex->id);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Globex Launch')
        ->assertSee('Globex assigned task')
        ->assertDontSee('Website Redesign')
        ->assertDontSee('Acme open task');
});

test('rejects a forged workspace switch', function () {
    $user = User::factory()->create();

    $own = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create(['name' => 'Own Workspace']);
    $ownProject = Project::factory()->for($own)->create(['name' => 'Own Project']);
    Task::factory()->for($ownProject)->assignedTo($user)->create(['title' => 'Own task']);

    $foreign = Workspace::factory()->withMember(User::factory()->create(), WorkspaceRole::Owner)->create(['name' => 'Foreign Workspace']);
    Project::factory()->for($foreign)->create(['name' => 'Foreign Project']);

    $this->actingAs($user);
    session([WorkspaceContext::SESSION_KEY => $own->id]);

    Livewire::test(WorkspaceSwitcher::class)
        ->call('switchTo', $foreign->id)
        ->assertNotFound();

    expect(session(WorkspaceContext::SESSION_KEY))->toBe($own->id);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Own task')
        ->assertDontSee('Foreign Workspace')
        ->assertDontSee('Foreign Project');
});

test('counts unfinished tasks and lists only the current users unfinished assignments', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();

    $acme = Workspace::factory()
        ->withMember($user, WorkspaceRole::Member)
        ->withMember($colleague, WorkspaceRole::Member)
        ->create();
    $other = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();

    $project = Project::factory()->for($acme)->create();
    $foreignProject = Project::factory()->for($other)->create();

    Task::factory()->for($project)->assignedTo($user)->create(['title' => 'My todo']);
    Task::factory()->for($project)->assignedTo($user)->inProgress()->create(['title' => 'My in progress']);
    Task::factory()->for($project)->assignedTo($user)->done()->create(['title' => 'My done']);
    Task::factory()->for($project)->assignedTo($colleague)->create(['title' => 'Colleague todo']);
    Task::factory()->for($project)->create(['title' => 'Unassigned todo']);
    Task::factory()->for($foreignProject)->assignedTo($user)->create(['title' => 'Foreign my todo']);

    $this->actingAs($user);
    session([WorkspaceContext::SESSION_KEY => $acme->id]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('1 project')
        ->assertSee('4 open tasks')
        ->assertSee('2 tasks')
        ->assertSee('My todo')
        ->assertSee('My in progress')
        ->assertDontSee('My done')
        ->assertDontSee('Colleague todo')
        ->assertDontSee('Unassigned todo')
        ->assertDontSee('Foreign my todo');
});
