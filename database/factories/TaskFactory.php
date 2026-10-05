<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state. Status and priority are omitted so the
     * database defaults (todo, medium) apply to created tasks.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'assignee_id' => null,
            'title' => fake()->sentence(4),
            'description' => null,
            'due_date' => null,
        ];
    }

    public function todo(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::Todo]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::InProgress]);
    }

    public function done(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::Done]);
    }

    public function highPriority(): static
    {
        return $this->state(fn () => ['priority' => TaskPriority::High]);
    }

    /**
     * Assign the task to the given user. The user must belong to the task's
     * project workspace for the tenant relationship to stay coherent.
     */
    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['assignee_id' => $user->id]);
    }
}
