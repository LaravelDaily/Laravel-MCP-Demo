<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\McpAuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McpAuditLog>
 */
class McpAuditLogFactory extends Factory
{
    /**
     * Define the model's default state. The default actor is a new member of
     * the entry's workspace so the tenant relationship stays coherent; pass
     * an actor with for($user, 'actor') to choose one explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $taskId = fake()->numberBetween(1, 1000);

        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => function (array $attributes): int {
                $actor = User::factory()->create();

                Workspace::query()->whereKey($attributes['workspace_id'])->firstOrFail()->memberships()->create([
                    'user_id' => $actor->id,
                    'role' => WorkspaceRole::Member,
                ]);

                return $actor->id;
            },
            'tool' => 'move_task',
            'entity_type' => McpAuditLog::ENTITY_TASK,
            'entity_id' => $taskId,
            'summary' => 'Moved task #'.$taskId.' "'.fake()->sentence(3).'" to done.',
        ];
    }
}
