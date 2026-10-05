<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $workspace_id
 * @property int $user_id
 * @property WorkspaceRole $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Membership extends Pivot
{
    protected $table = 'workspace_user';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
        ];
    }
}
