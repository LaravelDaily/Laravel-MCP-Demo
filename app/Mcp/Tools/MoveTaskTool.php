<?php

namespace App\Mcp\Tools;

use App\Actions\Tasks\ChangeTaskStatus;
use App\Enums\TaskStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('move_task')]
#[Description('Change the status of a task in the endpoint workspace. Owners and members only. Any status can transition to todo, in_progress or done, including reopening completed tasks. Returns the task summary.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class MoveTaskTool extends TeamBoardTool
{
    protected function execute(Request $request): ResponseFactory
    {
        $arguments = $this->validateArguments($request, ['task_id' => ['required', 'integer:strict', 'min:1']] + $this->taskStatusRules());
        $task = $this->context->task($arguments['task_id']);
        $task = app(ChangeTaskStatus::class)($this->actor($request), $task, $arguments['status']);
        $task->load(['project', 'assignee']);

        return Response::structured($this->taskSummary($task));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->min(1)->description('Task ID within the endpoint workspace.')->required(),
            'status' => $schema->string()->enum(array_column(TaskStatus::cases(), 'value'))->description('New task status; any transition is allowed.')->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->taskSummarySchema($schema);
    }
}
