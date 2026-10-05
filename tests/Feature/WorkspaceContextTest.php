<?php

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

test('selects the first membership when no workspace is selected', function () {
    $user = User::factory()->create();

    $lowest = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $higher = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();

    $this->actingAs($user);

    $current = app(WorkspaceContext::class)->current();

    expect($current->id)->toBe($lowest->id)
        ->and(session(WorkspaceContext::SESSION_KEY))->toBe($lowest->id);
});

test('uses the selected workspace from the session', function () {
    $user = User::factory()->create();

    $first = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $second = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();

    $this->actingAs($user);
    session([WorkspaceContext::SESSION_KEY => $second->id]);

    expect(app(WorkspaceContext::class)->current()->id)->toBe($second->id);
});

test('falls back when the selected membership has been removed', function () {
    Route::get('/_context/projects', function () {
        $context = app(WorkspaceContext::class);

        return [
            'workspace' => $context->current()?->slug,
            'projects' => $context->projects()->pluck('name')->all(),
        ];
    });

    $user = User::factory()->create();

    $remaining = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create(['slug' => 'remaining']);
    $removed = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create(['slug' => 'removed']);
    Project::factory()->for($remaining)->create(['name' => 'Remaining Project']);
    Project::factory()->for($removed)->create(['name' => 'Removed Workspace Project']);

    $this->actingAs($user);
    session([WorkspaceContext::SESSION_KEY => $removed->id]);
    $beforeRemoval = $this->get('/_context/projects')->json();

    $removed->members()->detach($user);
    $afterRemoval = $this->get('/_context/projects')->json();

    expect($beforeRemoval)->toBe(['workspace' => 'removed', 'projects' => ['Removed Workspace Project']])
        ->and($afterRemoval)->toBe(['workspace' => 'remaining', 'projects' => ['Remaining Project']])
        ->and(session(WorkspaceContext::SESSION_KEY))->toBe($remaining->id);
});

test('has no current workspace when the user has no memberships', function () {
    $user = User::factory()->create();

    Workspace::factory()->withMember(User::factory()->create(), WorkspaceRole::Owner)->create();

    $this->actingAs($user);

    $context = app(WorkspaceContext::class);

    expect($context->current())->toBeNull()
        ->and(session(WorkspaceContext::SESSION_KEY))->toBeNull()
        ->and(fn () => $context->projects())->toThrow(ModelNotFoundException::class);
});

test('rejects selection of a workspace the user does not belong to', function () {
    $user = User::factory()->create();

    $own = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $foreign = Workspace::factory()->withMember(User::factory()->create(), WorkspaceRole::Owner)->create();

    $this->actingAs($user);
    session([WorkspaceContext::SESSION_KEY => $own->id]);

    $context = app(WorkspaceContext::class);

    expect(fn () => $context->switchTo($foreign->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $context->switchTo($foreign->id + 1000))->toThrow(ModelNotFoundException::class)
        ->and(session(WorkspaceContext::SESSION_KEY))->toBe($own->id);
});

test('scopes records to the selected workspace even with another valid membership', function () {
    $user = User::factory()->create();

    $acme = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $globex = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();

    $acmeProject = Project::factory()->for($acme)->create(['name' => 'Acme Project']);
    $globexProject = Project::factory()->for($globex)->create(['name' => 'Globex Project']);

    Task::factory()->for($acmeProject)->create(['title' => 'Acme Task']);
    Task::factory()->for($globexProject)->create(['title' => 'Globex Task']);

    $this->actingAs($user);
    session([WorkspaceContext::SESSION_KEY => $acme->id]);

    $context = app(WorkspaceContext::class);

    expect($context->projects()->pluck('name')->all())->toBe(['Acme Project'])
        ->and($context->tasks()->pluck('title')->all())->toBe(['Acme Task']);

    $context->switchTo($globex->id);

    expect($context->projects()->pluck('name')->all())->toBe(['Globex Project'])
        ->and($context->tasks()->pluck('title')->all())->toBe(['Globex Task']);
});

test('resolves the current workspace once per request', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();

    $this->actingAs($user);
    $context = app(WorkspaceContext::class);
    $context->current();

    DB::enableQueryLog();
    $repeated = app(WorkspaceContext::class)->current();

    expect($repeated->id)->toBe($workspace->id)
        ->and(DB::getQueryLog())->toBeEmpty();
});

test('uses an explicit workspace instead of the session selection', function () {
    $user = User::factory()->create();

    $selected = Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $explicit = Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create();
    Project::factory()->for($selected)->create(['name' => 'Selected Project']);
    Project::factory()->for($explicit)->create(['name' => 'Explicit Project']);

    $this->actingAs($user);
    session([WorkspaceContext::SESSION_KEY => $selected->id]);

    $context = app(WorkspaceContext::class);
    $context->setExplicitWorkspace($explicit);

    expect($context->current()->id)->toBe($explicit->id)
        ->and($context->projects()->pluck('name')->all())->toBe(['Explicit Project'])
        ->and(session(WorkspaceContext::SESSION_KEY))->toBe($selected->id);
});

test('rejects an explicit workspace the user does not belong to', function () {
    $user = User::factory()->create();

    $own = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create();
    $foreign = Workspace::factory()->withMember(User::factory()->create(), WorkspaceRole::Owner)->create();

    $this->actingAs($user);
    $context = app(WorkspaceContext::class);

    expect(fn () => $context->setExplicitWorkspace($foreign))->toThrow(ModelNotFoundException::class)
        ->and($context->current()->id)->toBe($own->id);
});

test('does not carry workspace context into another request', function () {
    Route::get('/_context/workspace', function () {
        return ['workspace' => app(WorkspaceContext::class)->current()?->slug];
    });

    Route::get('/_context/explicit/{slug}', function (string $slug) {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();

        return ['workspace' => app(WorkspaceContext::class)->setExplicitWorkspace($workspace)->slug];
    });

    $alice = User::factory()->create();
    Workspace::factory()->withMember($alice, WorkspaceRole::Owner)->create(['slug' => 'acme']);
    Workspace::factory()->withMember($alice, WorkspaceRole::Viewer)->create(['slug' => 'sandbox']);

    $eve = User::factory()->create();
    Workspace::factory()->withMember($eve, WorkspaceRole::Owner)->create(['slug' => 'globex']);

    $this->actingAs($alice);
    $explicit = $this->get('/_context/explicit/sandbox')->json('workspace');
    $afterExplicit = $this->get('/_context/workspace')->json('workspace');

    $this->actingAs($eve);
    $otherUser = $this->get('/_context/workspace')->json('workspace');

    expect($explicit)->toBe('sandbox')
        ->and($afterExplicit)->toBe('acme')
        ->and($otherUser)->toBe('globex');
});
