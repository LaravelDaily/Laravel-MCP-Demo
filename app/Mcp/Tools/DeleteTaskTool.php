<?php

namespace App\Mcp\Tools;

use App\Actions\Tasks\DeleteTask;
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

#[Name('delete_task')]
#[Description('Permanently delete a task and all of its comments in the endpoint workspace. Owners only. This cannot be undone; confirm with the user before calling. Returns deleted: true and the task ID and title captured before deletion. Repeating the call reports that the task was not found.')]
#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class DeleteTaskTool extends TeamBoardTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->actorCanInWorkspace($request, 'deleteTasks');
    }

    protected function execute(Request $request): ResponseFactory
    {
        $arguments = $this->validateArguments($request, ['task_id' => ['required', 'integer:strict', 'min:1']]);
        $task = $this->context->task($arguments['task_id']);
        $deletedTask = ['id' => $task->id, 'title' => $task->title];
        app(DeleteTask::class)($this->actor($request), $task);

        return Response::structured(['deleted' => true, 'task' => $deletedTask]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->min(1)->description('Task ID within the endpoint workspace.')->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'deleted' => $schema->boolean()->required(),
            'task' => $schema->object([
                'id' => $schema->integer()->required(),
                'title' => $schema->string()->required(),
            ])->withoutAdditionalProperties()->required(),
        ];
    }
}
