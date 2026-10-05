<?php

namespace App\Mcp\Tools;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_my_tasks')]
#[Description('List tasks assigned to you in the endpoint workspace, including completed tasks by default. Optionally filter status and priority. Paginate in ascending ID order.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListMyTasksTool extends ReadTool
{
    protected function read(Request $request): ResponseFactory
    {
        $filters = $this->validateArguments($request, $this->paginationRules() + array_intersect_key($this->taskFilterRules(), array_flip(['status', 'priority'])));
        $filters['assignee_id'] = $request->user()?->getAuthIdentifier() ?? throw new AuthenticationException;

        return $this->taskResults($filters);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return $this->paginationSchema($schema) + array_intersect_key($this->taskFilterSchema($schema), array_flip(['status', 'priority']));
    }
}
