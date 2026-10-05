<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }

    /**
     * Add a membership for the given user once the workspace is created.
     */
    public function withMember(User $user, WorkspaceRole $role): static
    {
        return $this->afterCreating(function (Workspace $workspace) use ($user, $role) {
            $workspace->memberships()->create([
                'user_id' => $user->id,
                'role' => $role,
            ]);
        });
    }
}
