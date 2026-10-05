<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_tasks')]
#[Description('Search task titles and descriptions in the endpoint workspace with a required literal substring query. Optionally filter project and status. Paginate in ascending ID order. Search is case-insensitive for ASCII letters on SQLite; non-ASCII case folding depends on the database')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class SearchTasksTool extends ReadTool
{
    protected function read(Request $request): ResponseFactory
    {
        $filters = $this->validateArguments($request, $this->paginationRules() + array_intersect_key($this->taskFilterRules(), array_flip(['status', 'project_id'])) + [
            'query' => ['required', 'string', 'max:200'],
        ]);
        $filters['search'] = $filters['query'];

        return $this->taskResults($filters);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return $this->paginationSchema($schema) + array_intersect_key($this->taskFilterSchema($schema), array_flip(['status', 'project_id'])) + [
            'query' => $this->searchSchema($schema)->required(),
        ];
    }
}
