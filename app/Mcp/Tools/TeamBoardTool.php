<?php

namespace App\Mcp\Tools;

use App\Concerns\TaskValidationRules;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Workspaces\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

abstract class TeamBoardTool extends Tool
{
    use TaskValidationRules;

    public function __construct(protected WorkspaceContext $context) {}

    final public function handle(Request $request): Response|ResponseFactory
    {
        try {
            Gate::authorize('view', $this->context->currentOrFail());

            return $this->execute($request);
        } catch (ValidationException|AuthorizationException $exception) {
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            return Response::error('The record was not found in the current workspace.');
        } catch (Throwable $exception) {
            report($exception);

            return Response::error('Unable to complete this workspace operation. Please try again.');
        }
    }

    abstract protected function execute(Request $request): ResponseFactory;

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function validateArguments(Request $request, array $rules): array
    {
        $this->rejectUnsupportedFields($request->all(), array_keys($rules));

        foreach (['search', 'query', 'title', 'body'] as $field) {
            if (is_string($request->get($field))) {
                $request->merge([$field => trim($request->get($field))]);
            }
        }

        return $request->validate($rules, $this->taskValidationMessages());
    }

    /** @return array<string, mixed> */
    protected function taskSummary(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'project' => ['id' => $task->project->id, 'name' => $task->project->name],
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'assignee' => $task->assignee === null ? null : ['id' => $task->assignee->id, 'name' => $task->assignee->name],
            'due_date' => $task->due_date?->format('Y-m-d'),
        ];
    }

    /** @return array<string, Type> */
    protected function taskSummarySchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
            'project' => $schema->object($this->identitySchema($schema))->withoutAdditionalProperties()->required(),
            'status' => $schema->string()->enum(array_column(TaskStatus::cases(), 'value'))->required(),
            'priority' => $schema->string()->enum(array_column(TaskPriority::cases(), 'value'))->required(),
            'assignee' => $schema->object($this->identitySchema($schema))->withoutAdditionalProperties()->nullable()->required(),
            'due_date' => $schema->string()->format('date')->nullable()->required(),
        ];
    }

    /** @return array<string, Type> */
    protected function identitySchema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required(), 'name' => $schema->string()->required()];
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        return $actor instanceof User ? $actor : throw new AuthenticationException;
    }

    /**
     * Decide discovery eligibility from the actor's role in the endpoint
     * workspace. Discovery is a usability filter only: every operation
     * still authorizes through the shared task actions.
     */
    protected function actorCanInWorkspace(Request $request, string $ability): bool
    {
        $actor = $request->user();
        $workspace = $this->context->current();

        return $actor instanceof User && $workspace !== null && Gate::forUser($actor)->allows($ability, $workspace);
    }

    protected function taskDetails(Task $task): ResponseFactory
    {
        $task->load(['project', 'assignee']);

        return Response::structured($this->taskSummary($task) + ['description' => $task->description]);
    }

    /** @return array{title: StringType, description: StringType, priority: StringType, due_date: StringType} */
    protected function taskMetadataSchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->min(1)->max(255)->description('Trimmed, non-empty task title.'),
            'description' => $schema->string()->max(10000)->nullable()->description('Task description; null clears it.'),
            'priority' => $schema->string()->enum(array_column(TaskPriority::cases(), 'value'))->description('Task priority.'),
            'due_date' => $schema->string()->format('date')->nullable()->description('Due date in YYYY-MM-DD format; null clears it. Past dates are allowed.'),
        ];
    }

    /** @return array<string, Type> */
    protected function taskDetailsSchema(JsonSchema $schema): array
    {
        return $this->taskSummarySchema($schema) + ['description' => $schema->string()->nullable()->required()];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;
        $tool['outputSchema']['additionalProperties'] = false;

        return $tool;
    }
}
