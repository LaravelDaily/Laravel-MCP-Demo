<?php

namespace App\Actions\Tasks;

use App\Concerns\RequiresSelectedWorkspace;
use App\Concerns\TaskValidationRules;
use App\Enums\TaskPriority;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class CreateTask
{
    use RequiresSelectedWorkspace, TaskValidationRules;

    public function __construct(protected Gate $gate) {}

    /**
     * Create a task in a project of the selected workspace. New tasks
     * default to todo status and medium priority.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException|ModelNotFoundException|ValidationException
     */
    public function __invoke(User $actor, Project $project, array $input): Task
    {
        $project = $this->projectInSelectedWorkspace($project);

        $this->gate->forUser($actor)->authorize('create', [Task::class, $project]);

        if (is_string($input['title'] ?? null)) {
            $input['title'] = trim($input['title']);
        }

        $validated = validator(
            $input,
            $this->taskCreationRules($project->workspace_id),
            $this->taskValidationMessages(),
        )->validate();

        return $project->tasks()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'assignee_id' => $validated['assignee_id'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'priority' => $validated['priority'] ?? TaskPriority::Medium,
        ]);
    }
}
