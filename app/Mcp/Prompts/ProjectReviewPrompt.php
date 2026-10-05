<?php

namespace App\Mcp\Prompts;

use App\Concerns\TaskValidationRules;
use App\Models\Project;
use App\Workspaces\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
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
#[Description('Review one project in the endpoint workspace: read its instructions resource, inspect unfinished tasks with the read tools, and summarize overdue, high-priority, unassigned, and likely blocked work with three next actions. The server only generates these instructions; your client runs the review without changing data.')]
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

            $this->rejectUnsupportedFields($request->all(), ['project_id']);
            $request->validate([
                'project_id' => ['required', 'regex:/^[1-9][0-9]{0,9}$/', $this->projectExistsRule($workspace->id)],
            ], [
                'project_id.required' => __('The project_id argument is required. Use list_projects to find a project ID.'),
                'project_id.regex' => __('The project_id must be a positive whole-number project ID.'),
            ] + $this->taskValidationMessages());

            $project = $this->context->project((int) $request->get('project_id'));
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
                name: 'project_id',
                description: 'ID of a project in this workspace, as returned by list_projects (for example "12").',
                required: true,
            ),
        ];
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
