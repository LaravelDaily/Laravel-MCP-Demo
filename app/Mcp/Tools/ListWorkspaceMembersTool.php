<?php

namespace App\Mcp\Tools;

use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_workspace_members')]
#[Description('List members of the endpoint workspace with their workspace-specific roles. Returns public ID and name only, with no email. Optionally search names by literal substring; paginate in ascending ID order. Search is case-insensitive for ASCII letters on SQLite; non-ASCII case folding depends on the database')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListWorkspaceMembersTool extends ReadTool
{
    protected function read(Request $request): ResponseFactory
    {
        $filters = $this->validateArguments($request, $this->paginationRules() + [
            'search' => ['sometimes', 'required', 'string', 'max:200'],
        ]);
        $query = $this->context->currentOrFail()->members();

        if (isset($filters['search'])) {
            $this->literalSearch($query->getQuery(), ['users.name'], $filters['search']);
        }

        $members = $query->orderBy('users.id')->paginate($filters['per_page'] ?? 25, ['users.id', 'users.name'], 'page', $filters['page'] ?? 1);

        return $this->paginatedResponse($members, fn (User $member): array => [
            'id' => $member->id,
            'name' => $member->name,
            'role' => $member->membership->role->value,
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
        return $this->paginatedOutputSchema($schema, $schema->object($this->identitySchema($schema) + [
            'role' => $schema->string()->enum(array_column(WorkspaceRole::cases(), 'value'))->required(),
        ])->withoutAdditionalProperties());
    }
}
