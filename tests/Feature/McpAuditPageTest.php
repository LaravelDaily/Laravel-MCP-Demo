<?php

use App\Enums\WorkspaceRole;
use App\Livewire\McpAudit\Index as McpAuditIndex;
use App\Models\McpAuditLog;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

test('redirects guests to login for the audit page', function () {
    $response = $this->get(route('mcp-audit.index'));

    $response->assertRedirect(route('login'));
});

test('shows only current workspace audit entries to owners', function () {
    $this->travelTo('2026-10-05 12:00:00');
    $owner = User::factory()->create(['name' => 'Olivia Owner']);
    $current = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->create();
    $otherAccessible = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->create();
    $foreign = Workspace::factory()->create();
    $entry = fn (Workspace $workspace, string $summary, string $time, string $tool = 'move_task', int $entityId = 1) => McpAuditLog::factory()
        ->for($workspace)
        ->for($owner, 'actor')
        ->create(['summary' => $summary, 'tool' => $tool, 'entity_id' => $entityId, 'created_at' => $time, 'updated_at' => $time]);
    $entry($current, 'Oldest audited change on page two', '2026-10-05 10:00:00', 'add_comment', 7002);
    McpAuditLog::factory()->count(18)->for($current)->for($owner, 'actor')->create(['summary' => 'Filler audited change', 'created_at' => '2026-10-05 10:30:00']);
    $entry($current, 'Tied change with the lower ID', '2026-10-05 11:00:00');
    $entry($current, 'Tied change with the higher ID', '2026-10-05 11:00:00');
    $entry($current, 'Newest audited change', '2026-10-05 11:59:30', 'delete_task', 7001);
    $entry($otherAccessible, 'Other accessible workspace change', '2026-10-05 11:59:59');
    McpAuditLog::factory()->for($foreign)->create(['summary' => 'Foreign workspace change', 'created_at' => '2026-10-05 11:59:59']);
    $this->actingAs($owner);

    $firstPage = Livewire::test(McpAuditIndex::class);
    $secondPage = Livewire::test(McpAuditIndex::class)->call('gotoPage', 2);

    $firstPage->assertSeeInOrder(['Newest audited change', 'Tied change with the higher ID', 'Tied change with the lower ID', 'Filler audited change'])
        ->assertSee('2026-10-05 11:59:30 UTC')
        ->assertSee('delete_task')
        ->assertSee('Olivia Owner')
        ->assertSee('task #7001')
        ->assertDontSee('Oldest audited change on page two')
        ->assertDontSee('Other accessible workspace change')
        ->assertDontSee('Foreign workspace change');
    expect($firstPage->instance()->entries->total())->toBe(22);
    $secondPage->assertSee('Oldest audited change on page two')
        ->assertSee('task #7002')
        ->assertDontSee('Newest audited change')
        ->assertDontSee('Other accessible workspace change')
        ->assertDontSee('Foreign workspace change');
});

test('keeps audit entries after the acting user deletes their account', function () {
    $owner = User::factory()->create();
    $actor = User::factory()->create(['name' => 'Departed member']);
    $workspace = Workspace::factory()
        ->withMember($owner, WorkspaceRole::Owner)
        ->withMember($actor, WorkspaceRole::Member)
        ->create();
    $entry = McpAuditLog::factory()->for($workspace)->for($actor, 'actor')->create(['summary' => 'Change by a departed member']);

    $actor->delete();

    $this->assertModelExists($entry);
    expect($entry->fresh()->user_id)->toBeNull();

    $this->actingAs($owner);

    Livewire::test(McpAuditIndex::class)
        ->assertSee('Change by a departed member')
        ->assertSee('Deleted user')
        ->assertDontSee('Departed member');
});

test('returns 403 for members and viewers requesting the audit page', function (WorkspaceRole $role) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, $role)->create();
    McpAuditLog::factory()->for($workspace)->create(['summary' => 'Owner-only audited change']);
    $this->actingAs($user);

    $response = $this->get(route('mcp-audit.index'));

    $response->assertForbidden()
        ->assertDontSee('Owner-only audited change');
})->with([
    'member' => [WorkspaceRole::Member],
    'viewer' => [WorkspaceRole::Viewer],
]);

test('rechecks the owner role when paginating the audit page', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->create();
    McpAuditLog::factory()->for($workspace)->create(['summary' => 'Visible before the downgrade']);
    $this->actingAs($owner);
    $component = Livewire::test(McpAuditIndex::class)->assertSee('Visible before the downgrade');
    $workspace->memberships()->where('user_id', $owner->id)->update(['role' => WorkspaceRole::Member]);

    $response = $component->call('gotoPage', 1);

    $response->assertForbidden();
});

test('shows the audit navigation link to workspace owners', function () {
    $user = User::factory()->create();
    Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertSee(route('mcp-audit.index'));
});

test('hides the audit navigation link from members and viewers', function (WorkspaceRole $role) {
    $user = User::factory()->create();
    Workspace::factory()->withMember($user, $role)->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertDontSee(route('mcp-audit.index'));
})->with([
    'member' => [WorkspaceRole::Member],
    'viewer' => [WorkspaceRole::Viewer],
]);

test('shows the empty state on the audit page for a user without a workspace', function () {
    $user = User::factory()->create();
    McpAuditLog::factory()->create(['summary' => 'Unrelated workspace change']);
    $this->actingAs($user);

    $response = $this->get(route('mcp-audit.index'));

    $response->assertOk()
        ->assertSee('You are not a member of any workspace yet')
        ->assertDontSee('Unrelated workspace change');
});
