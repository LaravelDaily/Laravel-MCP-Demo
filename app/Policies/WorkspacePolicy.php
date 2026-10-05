<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    /**
     * Determine whether the user can view the workspace, its members, and
     * its projects, tasks, and comments.
     */
    public function view(User $user, Workspace $workspace): bool
    {
        return $user->belongsToWorkspace($workspace);
    }

    /**
     * Determine whether the user's role in the workspace allows creating,
     * editing, assigning, and moving tasks and adding comments. Used for
     * workspace-level decisions such as MCP tool discovery; record-level
     * operations still authorize through the task and comment policies.
     */
    public function contribute(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace)?->canContribute() === true;
    }

    /**
     * Determine whether the user's role in the workspace allows deleting
     * tasks, for workspace-level decisions such as MCP tool discovery.
     */
    public function deleteTasks(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace) === WorkspaceRole::Owner;
    }
}
