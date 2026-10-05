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

class UpdateTaskDetails
{
    use RequiresSelectedWorkspace, TaskValidationRules;

    public function __construct(protected Gate $gate) {}

    /**
     * Partially update a task's metadata. Omitted fields are unchanged;
     * an explicit null clears the description or due date, while title and
     * priority cannot be null. At least one editable field is required.
     * Status, assignment, and project ownership are separate operations
     * and cannot be changed here.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException|ModelNotFoundException|ValidationException
     */
    public function __invoke(User $actor, Task $task, array $input): Task
    {
        $task = $this->taskInSelectedWorkspace($task);

        $this->gate->forUser($actor)->authorize('update', $task);

        $editableFields = ['title', 'description', 'priority', 'due_date'];

        if (array_intersect(array_keys($input), $editableFields) === []) {
            throw ValidationException::withMessages([
                'update' => __('At least one task field is required.'),
            ]);
        }

        $input = array_intersect_key($input, array_flip($editableFields));

        if (array_key_exists('title', $input)) {
            $input['title'] = trim((string) $input['title']);
        }

        $validated = validator(
            $input,
            $this->taskUpdateRules(),
            $this->taskValidationMessages(),
        )->validate();

        $task->fill($validated)->save();

        return $task;
    }
}
