<?php

namespace App\Livewire;

use App\Models\Membership;
use App\Models\Workspace;
use App\Workspaces\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class WorkspaceSwitcher extends Component
{
    /**
     * List the user's memberships so the switcher only offers workspaces
     * the current user belongs to.
     *
     * @return Collection<int, Membership>
     */
    #[Computed]
    public function memberships(): Collection
    {
        return auth()->user()->memberships()
            ->with('workspace')
            ->orderBy('workspace_id')
            ->get();
    }

    #[Computed]
    public function current(): ?Workspace
    {
        return app(WorkspaceContext::class)->current();
    }

    /**
     * Switch the session selection to a workspace the user belongs to and
     * return to the dashboard, so a project or task page cannot keep
     * rendering the previous workspace's records. A foreign selection is
     * rejected as not found by the workspace context.
     */
    public function switchTo(int $workspaceId): void
    {
        app(WorkspaceContext::class)->switchTo($workspaceId);

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.workspace-switcher');
    }
}
