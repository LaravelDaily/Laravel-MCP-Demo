<?php

namespace App\Actions\Tasks;

use App\Concerns\RequiresSelectedWorkspace;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class DeleteTask
{
    use RequiresSelectedWorkspace;

    public function __construct(protected Gate $gate) {}

    /**
     * Delete the task and, through the database cascade, its comments.
     * Only workspace owners may delete tasks.
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function __invoke(User $actor, Task $task): void
    {
        $task = $this->taskInSelectedWorkspace($task);

        $this->gate->forUser($actor)->authorize('delete', $task);

        $task->delete();
    }
}
