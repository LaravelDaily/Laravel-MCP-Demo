<?php

namespace App\Mcp\Tools;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

abstract class ReadTool extends TeamBoardTool
{
    final protected function execute(Request $request): ResponseFactory
    {
        return $this->read($request);
    }

    abstract protected function read(Request $request): ResponseFactory;

    /** @return array<string, array<int, string>> */
    protected function paginationRules(): array
    {
        return [
            'page' => ['sometimes', 'required', 'integer:strict', 'min:1'],
            'per_page' => ['sometimes', 'required', 'integer:strict', 'between:1,100'],
        ];
    }

    /** @return array<string, mixed> */
    protected function taskFilterRules(): array
    {
        return [
            'project_id' => ['sometimes', 'required', 'integer:strict', 'min:1', $this->projectExistsRule($this->context->currentOrFail()->id)],
            'assignee_id' => ['sometimes', 'required', 'integer:strict', 'min:1', $this->assigneeExistsRule($this->context->currentOrFail()->id)],
            'status' => ['sometimes', 'required', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', 'required', Rule::enum(TaskPriority::class)],
        ];
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  list<literal-string>  $columns
     */
    protected function literalSearch(Builder $query, array $columns, string $search): void
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        $query->where(function (Builder $query) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $query->orWhereRaw("LOWER({$column}) like LOWER(?) escape '!'", [$pattern]);
            }
        });
    }

    /** @param array<string, mixed> $filters */
    protected function taskResults(array $filters): ResponseFactory
    {
        $query = $this->context->tasks()->with(['project', 'assignee']);

        foreach (['project_id', 'status', 'priority', 'assignee_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where('tasks.'.$field, $filters[$field]);
            }
        }

        if (isset($filters['search'])) {
            $this->literalSearch($query->getQuery(), ['tasks.title', 'tasks.description'], $filters['search']);
        }

        $tasks = $query->orderBy('tasks.id')->paginate($filters['per_page'] ?? 25, ['tasks.*'], 'page', $filters['page'] ?? 1);

        return $this->paginatedResponse($tasks, $this->taskSummary(...));
    }

    /**
     * @template T of Model
     *
     * @param  LengthAwarePaginator<int, T>  $results
     * @param  callable(T): array<string, mixed>  $format
     */
    protected function paginatedResponse(LengthAwarePaginator $results, callable $format): ResponseFactory
    {
        return Response::structured([
            'data' => $results->getCollection()->map($format)->all(),
            'meta' => [
                'page' => $results->currentPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'last_page' => $results->lastPage(),
            ],
        ]);
    }

    /** @return array<string, Type> */
    protected function paginationSchema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->min(1)->default(1)->description('Page number, starting at 1.'),
            'per_page' => $schema->integer()->min(1)->max(100)->default(25)->description('Maximum results per page (1–100).'),
        ];
    }

    /** @return array<string, Type> */
    protected function taskFilterSchema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->min(1)->description('Project ID within this workspace.'),
            'status' => $schema->string()->enum(array_column(TaskStatus::cases(), 'value'))->description('Task status.'),
            'priority' => $schema->string()->enum(array_column(TaskPriority::cases(), 'value'))->description('Task priority.'),
            'assignee_id' => $schema->integer()->min(1)->description('Member ID within this workspace.'),
        ];
    }

    protected function searchSchema(JsonSchema $schema): Type
    {
        return $schema->string()->min(1)->max(200)->description('Literal substring; trimmed before searching. Case-insensitive for ASCII letters on SQLite; non-ASCII case folding depends on the database.');
    }

    /** @return array<string, Type> */
    protected function paginatedOutputSchema(JsonSchema $schema, Type $item): array
    {
        return [
            'data' => $schema->array()->max(100)->items($item)->required(),
            'meta' => $schema->object([
                'page' => $schema->integer()->min(1)->required(),
                'per_page' => $schema->integer()->min(1)->max(100)->required(),
                'total' => $schema->integer()->min(0)->required(),
                'last_page' => $schema->integer()->min(1)->required(),
            ])->withoutAdditionalProperties()->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->paginatedOutputSchema($schema, $schema->object($this->taskSummarySchema($schema))->withoutAdditionalProperties());
    }
}
