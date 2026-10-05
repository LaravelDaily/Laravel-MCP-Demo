<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    /**
     * Define the model's default state. The default author is a new member of
     * the task's workspace so the tenant relationship stays coherent; pass an
     * author with for($user, 'author') to choose one explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'user_id' => function (array $attributes): int {
                $author = User::factory()->create();

                $workspace = Workspace::query()
                    ->whereHas('projects.tasks', fn (Builder $tasks) => $tasks->whereKey($attributes['task_id']))
                    ->firstOrFail();

                $workspace->memberships()->create([
                    'user_id' => $author->id,
                    'role' => WorkspaceRole::Member,
                ]);

                return $author->id;
            },
            'body' => fake()->paragraph(),
        ];
    }
}
