---
paths:
  - 'app/Mcp/**'
---

# Mcp

## Build MCP tools on the shared TeamBoard base
Tools extend App\Mcp\Tools\TeamBoardTool (read tools extend ReadTool) and implement execute()/read(); never override handle(). Validate with validateArguments() so unsupported keys are rejected, reuse TaskValidationRules and taskValidationMessages(), and resolve records only through WorkspaceContext. Mutations must call the shared actions in app/Actions/Tasks, never write models directly. Reuse taskSummary()/taskSummarySchema(); keep outputs bounded with no timestamps or private fields. Policy denials return specific Response::deny() messages. Mark writes non-idempotent unless every observable effect is idempotent.
