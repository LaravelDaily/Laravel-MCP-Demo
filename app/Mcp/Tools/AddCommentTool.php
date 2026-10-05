<?php

namespace App\Mcp\Tools;

use App\Actions\Tasks\AddComment;
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

#[Name('add_comment')]
#[Description('Add a comment to a task in the endpoint workspace. Owners and members only. The trimmed body must be non-empty and at most 5000 characters. Author comes from authenticated identity. Returns comment identity, task ID, body and author. Retrying can create duplicate comments.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class AddCommentTool extends TeamBoardTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->actorCanInWorkspace($request, 'contribute');
    }

    protected function execute(Request $request): ResponseFactory
    {
        $arguments = $this->validateArguments($request, ['task_id' => ['required', 'integer:strict', 'min:1']] + $this->commentRules());
        $task = $this->context->task($arguments['task_id']);
        $comment = app(AddComment::class)($this->actor($request), $task, $arguments['body']);
        $comment->load('author');

        return Response::structured([
            'id' => $comment->id,
            'task_id' => $comment->task_id,
            'body' => $comment->body,
            'author' => ['id' => $comment->author->id, 'name' => $comment->author->name],
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->min(1)->description('Task ID within the endpoint workspace.')->required(),
            'body' => $schema->string()->min(1)->max(5000)->description('Trimmed, non-empty comment body.')->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'task_id' => $schema->integer()->required(),
            'body' => $schema->string()->required(),
            'author' => $schema->object($this->identitySchema($schema))->withoutAdditionalProperties()->required(),
        ];
    }
}
