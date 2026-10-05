<?php

namespace App\Mcp\Tools;

use App\Enums\TaskStatus;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_projects')]
#[Description('List projects in the endpoint workspace with unfinished task counts. Optionally search names and descriptions by case-insensitive literal substring. Paginate in ascending ID order.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListProjectsTool extends ReadTool
{
    protected function read(Request $request): ResponseFactory
    {
        $filters = $this->validateArguments($request, $this->paginationRules() + [
            'search' => ['sometimes', 'required', 'string', 'max:200'],
        ]);
        $query = $this->context->projects()->withCount([
            'tasks as open_task_count' => fn (Builder $query) => $query->whereIn('status', [TaskStatus::Todo, TaskStatus::InProgress]),
        ]);

        if (isset($filters['search'])) {
            $this->literalSearch($query->getQuery(), ['projects.name', 'projects.description'], $filters['search']);
        }

        $projects = $query->orderBy('projects.id')->paginate($filters['per_page'] ?? 25, ['projects.*'], 'page', $filters['page'] ?? 1);

        return $this->paginatedResponse($projects, fn (Project $project): array => [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'open_task_count' => (int) $project->getAttribute('open_task_count'),
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return $this->paginationSchema($schema) + ['search' => $this->searchSchema($schema)];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->paginatedOutputSchema($schema, $schema->object([
            'id' => $schema->integer()->required(),
            'name' => $schema->string()->required(),
            'description' => $schema->string()->nullable()->required(),
            'open_task_count' => $schema->integer()->min(0)->required(),
        ])->withoutAdditionalProperties());
    }
}
