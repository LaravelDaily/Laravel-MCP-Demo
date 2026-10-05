<?php

namespace App\Mcp\Tools;

use App\Actions\Tasks\UpdateTaskDetails;
use App\Models\McpAuditLog;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('update_task')]
#[Description('Partially update task metadata in the endpoint workspace. Owners and members only. Supply at least one of title, description, priority or due_date. Omitted fields stay unchanged; null clears description or due_date. Status, assignment and project changes are not accepted. Returns task summary and description.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class UpdateTaskTool extends TeamBoardTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->actorCanInWorkspace($request, 'contribute');
    }

    protected function execute(Request $request): ResponseFactory
    {
        $arguments = $this->validateArguments($request, ['task_id' => ['required', 'integer:strict', 'min:1']] + $this->taskUpdateRules());
        $task = $this->context->task($arguments['task_id']);
        unset($arguments['task_id']);
        $task = DB::transaction(function () use ($request, $task, $arguments): Task {
            $task = app(UpdateTaskDetails::class)($this->actor($request), $task, $arguments);
            $this->recordAudit($request, McpAuditLog::ENTITY_TASK, $task->id, 'Updated '.$this->auditTaskLabel($task->id, $task->title).': '.implode(', ', array_keys($arguments)).'.');

            return $task;
        });

        return $this->taskDetails($task);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->integer()->min(1)->description('Task ID within the endpoint workspace.')->required()] + $this->taskMetadataSchema($schema);
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->taskDetailsSchema($schema);
    }
}
