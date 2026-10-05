<?php

namespace App\Concerns;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;

trait TaskValidationRules
{
    /**
     * Validation for creating a task. Status is not accepted; new tasks
     * always start as todo. The title is expected to be trimmed by the
     * caller before validation.
     *
     * @return array<string, array<int, string|Enum|Exists>>
     */
    public function taskCreationRules(int $workspaceId): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'assignee_id' => ['nullable', 'integer', $this->assigneeExistsRule($workspaceId)],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
        ];
    }

    /**
     * Validation for a partial metadata update. Fields with a "sometimes"
     * rule are validated and applied only when submitted; title and
     * priority cannot be null when present.
     *
     * @return array<string, array<int, string|Enum|Exists>>
     */
    public function taskUpdateRules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'priority' => ['sometimes', 'required', Rule::enum(TaskPriority::class)],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Validation for changing a task's status.
     *
     * @return array<string, array<int, string|Enum|Exists>>
     */
    public function taskStatusRules(): array
    {
        return [
            'status' => ['required', Rule::enum(TaskStatus::class)],
        ];
    }

    /**
     * Validation for assigning or unassigning a task. Any workspace
     * member, including a viewer, may be the assignee.
     *
     * @return array<string, array<int, string|Enum|Exists>>
     */
    public function taskAssignmentRules(int $workspaceId): array
    {
        return [
            'assignee_id' => ['nullable', 'integer', $this->assigneeExistsRule($workspaceId)],
        ];
    }

    /**
     * Validation for a comment body. The body is expected to be trimmed by
     * the caller before validation.
     *
     * @return array<string, array<int, string>>
     */
    public function commentRules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * User-facing messages shared by every layer applying these rules.
     *
     * @return array<string, string>
     */
    public function taskValidationMessages(): array
    {
        return [
            'assignee_id.exists' => __('The assignee must be a member of the current workspace.'),
            'status' => __('The status must be one of: todo, in_progress, done.'),
        ];
    }

    /**
     * Restrict an assignee ID to members of the given workspace.
     */
    protected function assigneeExistsRule(int $workspaceId): Exists
    {
        return Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspaceId);
    }
}
