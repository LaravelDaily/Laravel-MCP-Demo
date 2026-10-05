<?php

namespace App\Livewire\McpAudit;

use App\Models\McpAuditLog;
use App\Models\Workspace;
use App\Workspaces\WorkspaceContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('MCP audit log')]
class Index extends Component
{
    use WithPagination;

    /**
     * Forbid members and viewers before anything renders. Users without a
     * workspace see the shared empty state instead.
     */
    public function mount(): void
    {
        if ($this->workspace() !== null) {
            $this->authorize('viewMcpAudit', $this->workspace());
        }
    }

    /**
     * Resolve the selected workspace, or null when the user has no
     * memberships and the page shows its empty state.
     */
    #[Computed]
    public function workspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->current();
    }

    /**
     * List the selected workspace's MCP audit entries, newest first. The
     * owner check repeats on every render so a later role change denies
     * pagination requests too.
     *
     * @return LengthAwarePaginator<int, McpAuditLog>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        $workspace = app(WorkspaceContext::class)->currentOrFail();

        $this->authorize('viewMcpAudit', $workspace);

        return $workspace->mcpAuditLogs()
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function render(): View
    {
        return view('livewire.mcp-audit.index');
    }
}
