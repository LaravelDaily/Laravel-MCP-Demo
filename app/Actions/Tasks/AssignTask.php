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

class AssignTask
{
    use RequiresSelectedWorkspace, TaskValidationRules;

    public function __construct(protected Gate $gate) {}

    /**
     * Assign the task to a member of the selected workspace, or unassign
     * it with null. Any member, including a viewer, may be the assignee.
     *
     * @throws AuthorizationException|ModelNotFoundException|ValidationException
     */
    public function __invoke(User $actor, Task $task, ?int $assigneeId): Task
    {
        $task = $this->taskInSelectedWorkspace($task);

        $this->gate->forUser($actor)->authorize('assign', $task);

        $validated = validator(
            ['assignee_id' => $assigneeId],
            $this->taskAssignmentRules($task->project->workspace_id),
            $this->taskValidationMessages(),
        )->validate();

        $task->assignee_id = $validated['assignee_id'] ?? null;

        $task->save();

        return $task;
    }
}
