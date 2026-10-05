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

#[Name('list_tasks')]
#[Description('List tasks in the endpoint workspace. Combine project, status, priority, assignee and literal title/description search filters. Results are ordered by ascending ID and paginated; use meta.last_page to retrieve further pages. Search is case-insensitive for ASCII letters on SQLite; non-ASCII case folding depends on the database')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListTasksTool extends ReadTool
{
    protected function read(Request $request): ResponseFactory
    {
        $filters = $this->validateArguments($request, $this->paginationRules() + $this->taskFilterRules() + [
            'search' => ['sometimes', 'required', 'string', 'max:200'],
        ]);

        return $this->taskResults($filters);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return $this->paginationSchema($schema) + $this->taskFilterSchema($schema) + [
            'search' => $this->searchSchema($schema),
        ];
    }
}
