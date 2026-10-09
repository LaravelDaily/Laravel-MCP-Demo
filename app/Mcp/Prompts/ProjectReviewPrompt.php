<?php

namespace App\Mcp\Prompts;

use App\Concerns\TaskValidationRules;
use App\Models\Project;
use App\Workspaces\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use Throwable;

#[Name('project_review')]
#[Title('Project review')]
#[Description('Review one project in the endpoint workspace, chosen by name or ID: read its instructions resource, inspect unfinished tasks with the read tools, and summarize overdue, high-priority, unassigned, and likely blocked work with three next actions. The server only generates these instructions; your client runs the review without changing data.')]
class ProjectReviewPrompt extends Prompt
{
    use TaskValidationRules;

    public function __construct(protected WorkspaceContext $context) {}

    /**
     * Build the review instructions for a project of the endpoint workspace.
     * The server neither calls a model nor runs tools; the client follows
     * the returned message with its own read-only tool calls.
     */
    public function handle(Request $request): Response
    {
        try {
            $workspace = $this->context->currentOrFail();
            Gate::authorize('view', $workspace);

            $this->rejectUnsupportedFields($request->all(), ['project']);
            $request->validate([
                'project' => ['required', 'string', 'max:255'],
            ], [
                'project.required' => __('The project argument is required. Use list_projects to see the projects in this workspace.'),
                'project.string' => __('The project must be a project name or ID.'),
                'project.max' => __('The project must be a project name or ID.'),
            ]);

            $project = $this->findProject(trim($request->get('project')));
            Gate::authorize('view', $project);

            return Response::text($this->reviewInstructions($project, $workspace->name));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AuthorizationException $exception) {
            return Response::error($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return Response::error('Unable to prepare this workspace prompt. Please try again.');
        }
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'project',
                description: 'Name or ID of a project in this workspace (for example "Website Redesign", "website", or "12"). Names are matched case-insensitively, and part of a name works when it matches one project.',
                required: true,
            ),
        ];
    }

    /**
     * Resolve the project a person typed: an exact ID or name first, then a
     * unique partial name. Only the current workspace's projects are
     * searched, so foreign and missing projects give the same error.
     *
     * @throws ValidationException
     */
    protected function findProject(string $search): Project
    {
        $name = mb_strtolower($search);

        $exactMatches = $this->context->projects()
            ->where(function (Builder $query) use ($search, $name): void {
                $query->whereRaw('lower(name) = ?', [$name]);

                if (preg_match('/^[1-9][0-9]{0,9}$/', $search) === 1) {
                    $query->orWhere('id', (int) $search);
                }
            })
            ->limit(2)
            ->get();

        $matches = $exactMatches->isNotEmpty()
            ? $exactMatches
            : $this->context->projects()
                ->whereRaw("lower(name) like ? escape '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $name).'%'])
                ->limit(2)
                ->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        throw ValidationException::withMessages(['project' => $matches->isEmpty()
            ? __('No project in the current workspace matches ":search". Use list_projects to see the projects in this workspace.', ['search' => $search])
            : __('More than one project in the current workspace matches ":search". Use a longer name or the project ID from list_projects.', ['search' => $search]),
        ]);
    }

    protected function reviewInstructions(Project $project, string $workspaceName): string
    {
        $today = now()->toDateString();
        $timezone = config('app.timezone');
        $uri = 'project://'.$project->id.'/instructions';

        return <<<PROMPT
        Review the TeamBoard project "{$project->name}" (project ID {$project->id}) in the workspace "{$workspaceName}".
        Today's date is {$today} ({$timezone}).

        Gather context with read-only access:
        1. Read the project instructions resource {$uri} and apply its guidance when judging tasks. If your client cannot read MCP resources, say so and continue.
        2. Use list_tasks with project_id {$project->id} and status "todo", then with status "in_progress", to collect every unfinished task. Request the next page until meta.page equals meta.last_page.
        3. Use get_task for tasks that need more context, to read their description and latest comments.
        4. Use search_tasks or list_workspace_members only when they help clarify a finding.

        Identify, citing task IDs and titles:
        - Overdue tasks: unfinished tasks (status todo or in_progress) whose due_date is strictly before {$today}. A task due today is not overdue, and done tasks are never overdue.
        - High-priority unfinished tasks: priority "high".
        - Unassigned unfinished tasks: assignee is null.
        - Likely blockers: infer them from task descriptions and comments, such as waiting on another person, a dependency, missing information, or a failing integration. TeamBoard has no blocker status or field, so present each as an inference and quote the evidence.

        Then write:
        - A short summary of the project's state and the findings above.
        - The next three actions, ordered by importance, each referring to specific tasks.

        This review is read-only. Do not create, edit, assign, move, comment on, or delete anything. Change data only if the user makes a separate request after the review.
        PROMPT;
    }
}
