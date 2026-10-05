<?php

namespace App\Mcp\Resources;

use App\Concerns\TaskValidationRules;
use App\Workspaces\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;
use Throwable;

#[Name('project_instructions')]
#[Title('Project instructions')]
#[Description('Guidance for working on one project in the endpoint workspace, addressed by project ID (find IDs with list_projects). Returns JSON with project { id, name, description } and nullable instructions. Instructions are guidance for the client; the server does not execute them.')]
#[MimeType('application/json')]
class ProjectInstructionsResource extends Resource implements HasUriTemplate
{
    use TaskValidationRules;

    public const string URI_TEMPLATE = 'project://{project}/instructions';

    public function __construct(protected WorkspaceContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(self::URI_TEMPLATE);
    }

    /**
     * Read the project's instructions from the endpoint workspace. Missing,
     * foreign, and malformed project IDs share one not-found error, so the
     * response never reveals whether a project exists elsewhere.
     */
    public function handle(Request $request): Response
    {
        try {
            $workspace = $this->context->currentOrFail();
            Gate::authorize('view', $workspace);

            $request->validate([
                'project' => ['required', 'regex:/^[1-9][0-9]{0,9}$/', $this->projectExistsRule($workspace->id)],
            ], [
                'project' => __('The project was not found in the current workspace.'),
            ]);

            $project = $this->context->project((int) $request->get('project'));
            Gate::authorize('view', $project);

            return Response::json([
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'description' => $project->description,
                ],
                'instructions' => $project->instructions,
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException $exception) {
            return Response::error($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return Response::error('Unable to read this workspace resource. Please try again.');
        }
    }
}
