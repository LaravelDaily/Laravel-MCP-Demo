<?php

namespace App\Actions\Tasks;

use App\Concerns\RequiresSelectedWorkspace;
use App\Concerns\TaskValidationRules;
use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class AddComment
{
    use RequiresSelectedWorkspace, TaskValidationRules;

    public function __construct(protected Gate $gate) {}

    /**
     * Comment on a task of the selected workspace. The author always comes
     * from the authenticated actor and is never accepted from input.
     *
     * @throws AuthorizationException|ModelNotFoundException|ValidationException
     */
    public function __invoke(User $actor, Task $task, string $body): Comment
    {
        $task = $this->taskInSelectedWorkspace($task);

        $this->gate->forUser($actor)->authorize('create', [Comment::class, $task]);

        $validated = validator(
            ['body' => trim($body)],
            $this->commentRules(),
            $this->taskValidationMessages(),
        )->validate();

        return $task->comments()->create([
            'user_id' => $actor->id,
            'body' => $validated['body'],
        ]);
    }
}
