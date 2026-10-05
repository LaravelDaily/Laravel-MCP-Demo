<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CommentPolicy
{
    /**
     * Determine whether the user can view the comment. Any member of the
     * comment's workspace may view it.
     */
    public function view(User $user, Comment $comment): bool
    {
        return $user->belongsToWorkspace($comment->task->project->workspace_id);
    }

    /**
     * Determine whether the user can comment on the task. Comment editing
     * and deletion are out of scope; authors always come from the
     * authenticated identity.
     */
    public function create(User $user, Task $task): Response
    {
        return $user->roleIn($task->project->workspace_id)?->canContribute() === true
            ? Response::allow()
            : Response::deny('Your workspace role cannot add comments.');
    }
}
