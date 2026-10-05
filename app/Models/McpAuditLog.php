<?php

namespace App\Models;

use Database\Factories\McpAuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A successful MCP mutation. The entity reference is descriptive only and
 * remains after the task or comment is deleted.
 *
 * @property int $id
 * @property int $user_id
 * @property int $workspace_id
 * @property string $tool
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property string $summary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'workspace_id', 'tool', 'entity_type', 'entity_id', 'summary'])]
class McpAuditLog extends Model
{
    /** @use HasFactory<McpAuditLogFactory> */
    use HasFactory;

    public const string ENTITY_TASK = 'task';

    public const string ENTITY_COMMENT = 'comment';

    /**
     * The user who invoked the MCP tool.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
