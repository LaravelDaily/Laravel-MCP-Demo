<?php

namespace App\Mcp\Tools;

use App\Concerns\TaskValidationRules;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Workspaces\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('list_tasks')]
#[Description('List tasks in the endpoint workspace. Combine project, status, priority, assignee and case-insensitive literal title/description search filters. Results are ordered by ascending ID and paginated; use meta.last_page to retrieve further pages.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListTasksTool extends Tool
{
    use TaskValidationRules;

    public function __construct(protected WorkspaceContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            return $this->listTasks($request);
        } catch (ValidationException|AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return Response::error('Unable to list tasks. Please try again.');
        }
    }

    protected function listTasks(Request $request): ResponseFactory
    {
        $workspace = $this->context->currentOrFail();
        Gate::authorize('view', $workspace);

        $rules = [
            'page' => ['sometimes', 'required', 'integer:strict', 'min:1'],
            'per_page' => ['sometimes', 'required', 'integer:strict', 'between:1,100'],
            'project_id' => ['sometimes', 'required', 'integer:strict', 'min:1', $this->projectExistsRule($workspace->id)],
            'assignee_id' => ['sometimes', 'required', 'integer:strict', 'min:1', $this->assigneeExistsRule($workspace->id)],
            'status' => ['sometimes', 'required', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', 'required', Rule::enum(TaskPriority::class)],
            'search' => ['sometimes', 'required', 'string', 'max:200'],
        ];

        $this->rejectUnsupportedFields($request->all(), array_keys($rules));

        if (is_string($request->get('search'))) {
            $request->merge(['search' => trim($request->get('search'))]);
        }

        $filters = $request->validate($rules, $this->taskValidationMessages());

        $query = $this->context->tasks()->with(['project', 'assignee']);

        foreach (['project_id', 'status', 'priority', 'assignee_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where('tasks.'.$field, $filters[$field]);
            }
        }

        if (isset($filters['search'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("LOWER(tasks.title) like LOWER(?) escape '!'", [$pattern])
                    ->orWhereRaw("LOWER(tasks.description) like LOWER(?) escape '!'", [$pattern]);
            });
        }

        $tasks = $query->orderBy('tasks.id')->paginate($filters['per_page'] ?? 25, ['tasks.*'], 'page', $filters['page'] ?? 1);

        return Response::structured([
            'data' => $tasks->getCollection()->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'project' => ['id' => $task->project->id, 'name' => $task->project->name],
                'status' => $task->status->value,
                'priority' => $task->priority->value,
                'assignee' => $task->assignee === null ? null : ['id' => $task->assignee->id, 'name' => $task->assignee->name],
                'due_date' => $task->due_date?->format('Y-m-d'),
            ])->all(),
            'meta' => [
                'page' => $tasks->currentPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
                'last_page' => $tasks->lastPage(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->min(1)->default(1)->description('Page number, starting at 1.'),
            'per_page' => $schema->integer()->min(1)->max(100)->default(25)->description('Maximum tasks per page (1–100).'),
            'project_id' => $schema->integer()->min(1)->description('Project ID within this workspace.'),
            'status' => $schema->string()->enum(array_column(TaskStatus::cases(), 'value'))->description('Task status.'),
            'priority' => $schema->string()->enum(array_column(TaskPriority::cases(), 'value'))->description('Task priority.'),
            'assignee_id' => $schema->integer()->min(1)->description('Member ID within this workspace.'),
            'search' => $schema->string()->min(1)->max(200)->description('Case-insensitive literal substring of title or description; trimmed before searching.'),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'data' => $schema->array()->max(100)->items($schema->object([
                'id' => $schema->integer()->required(),
                'title' => $schema->string()->required(),
                'project' => $schema->object([
                    'id' => $schema->integer()->required(),
                    'name' => $schema->string()->required(),
                ])->withoutAdditionalProperties()->required(),
                'status' => $schema->string()->enum(array_column(TaskStatus::cases(), 'value'))->required(),
                'priority' => $schema->string()->enum(array_column(TaskPriority::cases(), 'value'))->required(),
                'assignee' => $schema->object([
                    'id' => $schema->integer()->required(),
                    'name' => $schema->string()->required(),
                ])->withoutAdditionalProperties()->nullable()->required(),
                'due_date' => $schema->string()->format('date')->nullable()->required(),
            ])->withoutAdditionalProperties())->required(),
            'meta' => $schema->object([
                'page' => $schema->integer()->min(1)->required(),
                'per_page' => $schema->integer()->min(1)->max(100)->required(),
                'total' => $schema->integer()->min(0)->required(),
                'last_page' => $schema->integer()->min(1)->required(),
            ])->withoutAdditionalProperties()->required(),
        ];
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
