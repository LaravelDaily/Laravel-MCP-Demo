<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Workspaces\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveMcpWorkspace
{
    public function __construct(protected WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $workspace = $user->workspaces()->where('slug', $request->route('workspace'))->firstOrFail();
        $this->context->setExplicitWorkspace($workspace);

        return $next($request);
    }
}
