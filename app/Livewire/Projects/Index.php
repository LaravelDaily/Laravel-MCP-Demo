<?php

namespace App\Livewire\Projects;

use App\Concerns\ProjectValidationRules;
use App\Models\Project;
use App\Models\Workspace;
use App\Workspaces\WorkspaceContext;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Projects')]
class Index extends Component
{
    use ProjectValidationRules, WithPagination;

    public string $name = '';

    public ?string $description = null;

    public ?string $instructions = null;

    /**
     * ID of the project being edited, null when the form creates a project.
     */
    #[Locked]
    public ?int $editingId = null;

    /**
     * ID of the project awaiting delete confirmation.
     */
    #[Locked]
    public ?int $deletingId = null;

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
     * @return LengthAwarePaginator<int, Project>
     */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        return app(WorkspaceContext::class)
            ->projects()
            ->withCount('tasks')
            ->orderBy('id')
            ->paginate(10);
    }

    /**
     * Whether the current user may create, edit, and delete projects in the
     * selected workspace.
     */
    #[Computed]
    public function canManageProjects(): bool
    {
        $workspace = $this->workspace();

        return $workspace !== null && auth()->user()->can('create', [Project::class, $workspace]);
    }

    public function startCreating(): void
    {
        $this->reset(['name', 'description', 'instructions', 'editingId']);

        Flux::modal('project-form')->show();
    }

    /**
     * Load a project of the selected workspace into the edit form. A foreign
     * or missing ID is rejected as not found.
     */
    public function startEditing(int $projectId): void
    {
        $project = app(WorkspaceContext::class)->project($projectId);

        $this->editingId = $project->id;
        $this->name = $project->name;
        $this->description = $project->description;
        $this->instructions = $project->instructions;

        Flux::modal('project-form')->show();
    }

    /**
     * Create or update the selected workspace's project. The record is
     * re-resolved through the workspace context and re-authorized on every
     * call, so mounting the component once proves nothing.
     */
    public function save(): void
    {
        $context = app(WorkspaceContext::class);

        $this->name = trim($this->name);
        $this->description = $this->description === '' ? null : $this->description;
        $this->instructions = $this->instructions === '' ? null : $this->instructions;

        if ($this->editingId !== null) {
            $project = $context->project($this->editingId);

            $this->authorize('update', $project);

            $project->update($this->validate($this->projectRules()));

            Flux::toast(variant: 'success', text: __('Project updated.'));
        } else {
            $workspace = $context->currentOrFail();

            $this->authorize('create', [Project::class, $workspace]);

            $workspace->projects()->create($this->validate($this->projectRules()));

            Flux::toast(variant: 'success', text: __('Project created.'));
        }

        Flux::modal('project-form')->close();
    }

    public function confirmDelete(int $projectId): void
    {
        $this->deletingId = $projectId;

        Flux::modal('project-delete')->show();
    }

    /**
     * Delete the confirmed project and, through the database cascades, its
     * tasks and their comments.
     */
    public function delete(): void
    {
        if ($this->deletingId === null) {
            return;
        }

        $project = app(WorkspaceContext::class)->project($this->deletingId);

        $this->authorize('delete', $project);

        $project->delete();

        $this->deletingId = null;

        Flux::modal('project-delete')->close();

        Flux::toast(variant: 'success', text: __('Project deleted.'));
    }

    public function render(): View
    {
        return view('livewire.projects.index');
    }
}
