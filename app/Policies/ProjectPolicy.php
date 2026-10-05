<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

class ProjectPolicy
{
    /**
     * Determine whether the user can view the project. Any member of the
     * project's workspace may view it.
     */
    public function view(User $user, Project $project): bool
    {
        return $user->belongsToWorkspace($project->workspace_id);
    }

    /**
     * Determine whether the user can create a project in the workspace.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace) === WorkspaceRole::Owner;
    }

    /**
     * Determine whether the user can edit the project.
     */
    public function update(User $user, Project $project): bool
    {
        return $user->roleIn($project->workspace_id) === WorkspaceRole::Owner;
    }

    /**
     * Determine whether the user can delete the project.
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->roleIn($project->workspace_id) === WorkspaceRole::Owner;
    }
}
