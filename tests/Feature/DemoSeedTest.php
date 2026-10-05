<?php

use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\TeamBoardDemoSeeder;

test('seeds recognizable isolated workspaces and usable demo accounts', function () {
    $this->freezeTime();

    $this->seed(TeamBoardDemoSeeder::class);

    $acme = Workspace::where('slug', 'acme')->firstOrFail();
    $sandbox = Workspace::where('slug', 'sandbox')->firstOrFail();
    $globex = Workspace::where('slug', 'globex')->firstOrFail();

    $alice = User::where('email', 'alice@example.com')->firstOrFail();
    $bob = User::where('email', 'bob@example.com')->firstOrFail();
    $charlie = User::where('email', 'charlie@example.com')->firstOrFail();
    $dana = User::where('email', 'dana@example.com')->firstOrFail();
    $eve = User::where('email', 'eve@example.com')->firstOrFail();

    expect($alice->roleIn($acme))->toBe(WorkspaceRole::Owner)
        ->and($bob->roleIn($acme))->toBe(WorkspaceRole::Member)
        ->and($charlie->roleIn($acme))->toBe(WorkspaceRole::Viewer)
        ->and($dana->roleIn($acme))->toBe(WorkspaceRole::Owner)
        ->and($dana->roleIn($sandbox))->toBe(WorkspaceRole::Viewer)
        ->and($eve->roleIn($globex))->toBe(WorkspaceRole::Owner);

    expect($alice->roleIn($globex))->toBeNull()
        ->and($bob->roleIn($globex))->toBeNull()
        ->and($charlie->roleIn($globex))->toBeNull()
        ->and($dana->roleIn($globex))->toBeNull();

    foreach ([$alice, $bob, $charlie, $dana, $eve] as $demoUser) {
        expect($demoUser->hasVerifiedEmail())->toBeTrue();
    }

    foreach (['alice', 'bob', 'charlie', 'dana', 'eve'] as $account) {
        expect(auth()->attempt(['email' => "{$account}@example.com", 'password' => 'password']))->toBeTrue();
        auth()->logout();
    }

    $website = Project::where(['workspace_id' => $acme->id, 'name' => 'Website Redesign'])->firstOrFail();
    $billing = Project::where(['workspace_id' => $acme->id, 'name' => 'Billing Improvements'])->firstOrFail();

    expect($website->instructions)->not->toBeNull()
        ->and($billing->instructions)->toContain('Acceptance criteria')
        ->and($billing->instructions)->toContain('reproduction steps');

    expect($acme->tasks()->pluck('title')->all())->toContain(
        'Redesign pricing page',
        'Fix mobile navigation',
        'Prepare launch checklist',
        'Investigate failed Stripe webhook',
        'Add invoice download',
        'Improve subscription cancellation flow',
    )->and($acme->tasks()->pluck('status')->map(fn (TaskStatus $status) => $status->value)->unique()->values()->all())->toContain('todo', 'in_progress', 'done')
        ->and($acme->tasks()->whereNull('assignee_id')->exists())->toBeTrue()
        ->and($acme->tasks()->whereNotNull('assignee_id')->exists())->toBeTrue()
        ->and($globex->tasks()->pluck('title')->all())->toContain('Migrate payroll data to the new provider')
        ->and($sandbox->tasks()->pluck('title')->all())->toContain('Test webhook integration');

    $stripeWebhook = Task::where('title', 'Investigate failed Stripe webhook')->firstOrFail();

    expect($stripeWebhook->status)->toBe(TaskStatus::InProgress)
        ->and($stripeWebhook->due_date->isPast())->toBeTrue()
        ->and($stripeWebhook->comments()->count())->toBeGreaterThan(0)
        ->and($acme->tasks()->whereDate('due_date', '>', now()->toDateString())->exists())->toBeTrue();

    $cancellation = Task::where('title', 'Improve subscription cancellation flow')->firstOrFail();

    expect($cancellation->status)->toBe(TaskStatus::Done)
        ->and($cancellation->due_date->isPast())->toBeTrue();
});
