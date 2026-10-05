<?php

namespace App\Mcp\Tools;

use App\Actions\Tasks\AssignTask;
use App\Models\McpAuditLog;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('assign_task')]
#[Description('Assign or unassign a task in the endpoint workspace. Owners and members only. Supply assignee_id as a workspace member ID (including viewers), or null to unassign. Returns the task summary.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class AssignTaskTool extends TeamBoardTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->actorCanInWorkspace($request, 'contribute');
    }

    protected function execute(Request $request): ResponseFactory
    {
        $rules = $this->taskAssignmentRules($this->context->currentOrFail()->id);
        $rules['assignee_id'] = ['present', ...$rules['assignee_id'], 'integer:strict', 'min:1'];
        $arguments = $this->validateArguments($request, ['task_id' => ['required', 'integer:strict', 'min:1']] + $rules);
        $task = $this->context->task($arguments['task_id']);
        $task = DB::transaction(function () use ($request, $task, $arguments): Task {
            $task = app(AssignTask::class)($this->actor($request), $task, $arguments['assignee_id']);
            $task->load(['project', 'assignee']);
            $label = $this->auditTaskLabel($task->id, $task->title);
            $this->recordAudit($request, McpAuditLog::ENTITY_TASK, $task->id, $task->assignee === null
                ? 'Unassigned '.$label.'.'
                : 'Assigned '.$label.' to '.Str::limit($task->assignee->name, 80).'.');

            return $task;
        });

        return Response::structured($this->taskSummary($task));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->min(1)->description('Task ID within the endpoint workspace.')->required(),
            'assignee_id' => $schema->integer()->min(1)->nullable()->description('Workspace member ID, including viewers; null unassigns the task. Must be supplied.')->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->taskSummarySchema($schema);
    }
}
