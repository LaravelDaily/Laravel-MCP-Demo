<?php

namespace App\Workspaces;

use App\Models\Membership;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use RuntimeException;

class WorkspaceContext
{
    /**
     * Session key holding the browser's selected workspace ID.
     */
    public const string SESSION_KEY = 'workspace_id';

    /**
     * Request attribute holding a workspace set explicitly by an adapter such
     * as the MCP endpoint. Request attributes are discarded with the request,
     * so the selection never reaches another request or a queued job.
     */
    public const string EXPLICIT_WORKSPACE_ATTRIBUTE = 'teamboard.explicit_workspace';

    /**
     * Resolve the current workspace for the authenticated user. An explicit
     * workspace set for this request takes precedence over the session
     * selection; a stale session selection is cleared and replaced by the
     * user's first membership ordered by workspace ID.
     */
    public function current(): ?Workspace
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $explicitWorkspace = request()->attributes->get(self::EXPLICIT_WORKSPACE_ATTRIBUTE);

        if ($explicitWorkspace instanceof Workspace) {
            return $explicitWorkspace;
        }

        $selectedId = session(self::SESSION_KEY);

        if ($selectedId !== null) {
            $workspace = $this->accessibleWorkspace($user, (int) $selectedId);

            if ($workspace !== null) {
                return $workspace;
            }

            session()->forget(self::SESSION_KEY);
        }

        $membership = $user->memberships()
            ->orderBy('workspace_id')
            ->first();

        if ($membership === null) {
            return null;
        }

        session([self::SESSION_KEY => $membership->workspace_id]);

        return $membership->workspace;
    }

    /**
     * Resolve the current workspace or fail when the user has none.
     */
    public function currentOrFail(): Workspace
    {
        return $this->current() ?? throw new RuntimeException('The user has no current workspace.');
    }

    /**
     * Restrict a query to the projects of the current workspace.
     *
     * @return HasMany<Project, Workspace>
     */
    public function projects(): HasMany
    {
        return $this->currentOrFail()->projects();
    }

    /**
     * Restrict a query to the tasks of the current workspace through its
     * projects.
     *
     * @return HasManyThrough<Task, Project, Workspace>
     */
    public function tasks(): HasManyThrough
    {
        return $this->currentOrFail()->tasks();
    }

    /**
     * Switch the browser selection to a workspace the current user belongs
     * to. A foreign selection is rejected and never stored.
     */
    public function switchTo(int $workspaceId): Workspace
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthorizationException('You must be authenticated to select a workspace.');
        }

        $workspace = $this->accessibleWorkspace($user, $workspaceId);

        if ($workspace === null) {
            throw new AuthorizationException('You do not belong to the selected workspace.');
        }

        session([self::SESSION_KEY => $workspace->id]);

        return $workspace;
    }

    /**
     * Set the workspace for the current request lifecycle, for request
     * adapters such as the MCP endpoint that resolve the workspace from
     * their own URL. The user's membership is validated here so callers
     * cannot inject a foreign workspace.
     */
    public function setExplicitWorkspace(Workspace $workspace): Workspace
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->belongsToWorkspace($workspace)) {
            throw new AuthorizationException('You do not belong to the selected workspace.');
        }

        request()->attributes->set(self::EXPLICIT_WORKSPACE_ATTRIBUTE, $workspace);

        return $workspace;
    }

    /**
     * Find the workspace behind an ID the user can access.
     */
    protected function accessibleWorkspace(User $user, int $workspaceId): ?Workspace
    {
        $membership = Membership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->first();

        return $membership?->workspace;
    }
}
