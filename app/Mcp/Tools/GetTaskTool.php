<?php

namespace App\Mcp\Tools;

use App\Models\Comment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_task')]
#[Description('Read one task in the endpoint workspace, including its description and latest 20 comments ordered by creation time descending then ID descending. comments_total counts the complete history; no pagination input.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class GetTaskTool extends ReadTool
{
    protected function read(Request $request): ResponseFactory
    {
        $arguments = $this->validateArguments($request, [
            'task_id' => ['required', 'integer:strict', 'min:1'],
        ]);
        $task = $this->context->task($arguments['task_id']);
        Gate::authorize('view', $task);
        $task->load(['project', 'assignee']);
        $comments = $task->comments()->with('author:id,name')->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get();

        return Response::structured($this->taskSummary($task) + [
            'description' => $task->description,
            'comments' => $comments->map(fn (Comment $comment): array => [
                'id' => $comment->id,
                'body' => $comment->body,
                'author' => ['id' => $comment->author->id, 'name' => $comment->author->name],
            ])->all(),
            'comments_total' => $task->comments()->count(),
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->integer()->min(1)->description('Task ID within this workspace.')->required()];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->taskSummarySchema($schema) + [
            'description' => $schema->string()->nullable()->required(),
            'comments' => $schema->array()->max(20)->items($schema->object([
                'id' => $schema->integer()->required(),
                'body' => $schema->string()->required(),
                'author' => $schema->object($this->identitySchema($schema))->withoutAdditionalProperties()->required(),
            ])->withoutAdditionalProperties())->required(),
            'comments_total' => $schema->integer()->min(0)->required(),
        ];
    }
}
