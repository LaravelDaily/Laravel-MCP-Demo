<?php

namespace App\Workspaces;

use App\Models\Membership;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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
     * Request attribute caching the session-based resolution for the rest of
     * the request, keyed by user so a change of identity resolves again.
     */
    public const string RESOLVED_WORKSPACE_ATTRIBUTE = 'teamboard.resolved_workspace';

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

        $resolved = request()->attributes->get(self::RESOLVED_WORKSPACE_ATTRIBUTE);

        if (is_array($resolved) && $resolved['user_id'] === $user->id) {
            return $resolved['workspace'];
        }

        return $this->remember($user, $this->resolveFromSession($user));
    }

    /**
     * Resolve the browser's selected workspace, replacing a stale selection
     * with the user's first membership.
     */
    protected function resolveFromSession(User $user): ?Workspace
    {
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
     * Resolve the current workspace, or fail with a not-found error when the
     * user has none.
     *
     * @throws ModelNotFoundException<Workspace>
     */
    public function currentOrFail(): Workspace
    {
        return $this->current() ?? throw (new ModelNotFoundException)->setModel(Workspace::class);
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
     * to. A foreign or missing workspace is rejected as not found and never
     * stored, so the response does not reveal whether it exists.
     *
     * @throws ModelNotFoundException<Workspace>
     */
    public function switchTo(int $workspaceId): Workspace
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $workspace = $this->accessibleWorkspace($user, $workspaceId);

        if ($workspace === null) {
            throw (new ModelNotFoundException)->setModel(Workspace::class, [$workspaceId]);
        }

        session([self::SESSION_KEY => $workspace->id]);
        $this->remember($user, $workspace);

        return $workspace;
    }

    /**
     * Cache the resolved workspace for the rest of the request.
     */
    protected function remember(User $user, ?Workspace $workspace): ?Workspace
    {
        request()->attributes->set(self::RESOLVED_WORKSPACE_ATTRIBUTE, [
            'user_id' => $user->id,
            'workspace' => $workspace,
        ]);

        return $workspace;
    }

    /**
     * Set the workspace for the current request lifecycle, for request
     * adapters such as the MCP endpoint that resolve the workspace from
     * their own URL. The user's membership is validated here so callers
     * cannot inject a foreign workspace; one is rejected as not found.
     *
     * @throws ModelNotFoundException<Workspace>
     */
    public function setExplicitWorkspace(Workspace $workspace): Workspace
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        if (! $user->belongsToWorkspace($workspace)) {
            throw (new ModelNotFoundException)->setModel(Workspace::class, [$workspace->id]);
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
