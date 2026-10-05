<?php

namespace App\Actions\Tasks;

use App\Concerns\RequiresSelectedWorkspace;
use App\Concerns\TaskValidationRules;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class ChangeTaskStatus
{
    use RequiresSelectedWorkspace, TaskValidationRules;

    public function __construct(protected Gate $gate) {}

    /**
     * Move the task to one of the three statuses. Any status may
     * transition to any other.
     *
     * @throws AuthorizationException|ModelNotFoundException|ValidationException
     */
    public function __invoke(User $actor, Task $task, string $status): Task
    {
        $task = $this->taskInSelectedWorkspace($task);

        $this->gate->forUser($actor)->authorize('move', $task);

        $validated = validator(
            ['status' => $status],
            $this->taskStatusRules(),
            $this->taskValidationMessages(),
        )->validate();

        $task->status = $validated['status'];

        $task->save();

        return $task;
    }
}
