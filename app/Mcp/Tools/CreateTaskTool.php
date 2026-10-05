<?php

namespace App\Mcp\Tools;

use App\Actions\Tasks\CreateTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('create_task')]
#[Description('Create a task in a project of the endpoint workspace. Owners and members only. New tasks default to todo status and medium priority. Any workspace member may be assigned. Returns task summary and description. Retrying can create duplicate tasks.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class CreateTaskTool extends TeamBoardTool
{
    protected function execute(Request $request): ResponseFactory
    {
        $rules = $this->taskCreationRules($this->context->currentOrFail()->id);
        $rules['priority'] = ['sometimes', 'required', ...$rules['priority']];
        $rules['assignee_id'] = ['nullable', 'integer:strict', 'min:1', $this->assigneeExistsRule($this->context->currentOrFail()->id)];
        $arguments = $this->validateArguments($request, ['project_id' => ['required', 'integer:strict', 'min:1']] + $rules);
        $project = $this->context->project($arguments['project_id']);
        unset($arguments['project_id']);
        $task = app(CreateTask::class)($this->actor($request), $project, $arguments);

        return $this->taskDetails($task);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        $metadata = $this->taskMetadataSchema($schema);
        $metadata['title']->required();
        $metadata['priority']->default('medium');

        return ['project_id' => $schema->integer()->min(1)->description('Project ID within the endpoint workspace.')->required()] + $metadata + [
            'assignee_id' => $schema->integer()->min(1)->nullable()->description('Member ID within this workspace, including viewers; null leaves the task unassigned.'),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->taskDetailsSchema($schema);
    }
}
