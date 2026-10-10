# Lesson 07 — OAuth with Passport: "Connect to TeamBoard" without copying tokens

| | |
| --- | --- |
| Starts at | `course/06-production-basics` |
| Ends at | `course/07-oauth` |
| Length goal | Two videos: 07a 10–15 minutes, 07b 15–20 minutes |

## Goal

Replace pasted Sanctum tokens with the flow customers expect from a real SaaS: add the URL, a browser window opens, log in, click **Approve**, done.

- **07a — How the flow works.** Show the result first, then take the flow apart step by step with `curl` and the database.
- **07b — How it's built.** The small amount of code that makes it work, the same permissions under OAuth, and the tests.

The key message is that **OAuth answers "who is calling?". Your policies still answer "what may they do?"** Nothing in the tools, policies, tenant isolation, audit log, or rate limit changes. The only change on the MCP route is `auth:sanctum` → `auth:api`.

## Commands

Before recording:

```bash
git switch --detach course/07-oauth
composer install
php artisan passport:keys          # once; writes the gitignored storage/oauth-*.key files
php artisan migrate:fresh --seed   # adds the oauth_* tables, drops personal_access_tokens
npm run build                      # new consent screen and MCP access page
php artisan config:clear
```

Check that `TEAMBOARD_MCP_RATE_LIMIT` is not still set to `5` from lesson 06.

The token-based servers from lessons 01–06 no longer work. Remove them first:

```bash
claude mcp remove teamboard-alice -s local
claude mcp remove teamboard-bob -s local
claude mcp remove teamboard-charlie -s local
claude mcp remove teamboard-dana-acme -s local
claude mcp remove teamboard-dana-sandbox -s local
```

Commands used to build this lesson's code:

```bash
composer require laravel/passport
php artisan passport:keys
php artisan vendor:publish --tag=passport-config
php artisan vendor:publish --tag=passport-migrations
php artisan vendor:publish --tag=mcp-config
php artisan migrate
composer remove laravel/sanctum
php artisan make:migration drop_personal_access_tokens_table
php artisan make:test --pest McpOAuthTest
```

`resources/views/mcp/authorize.blade.php` was written by hand. Laravel MCP also ships a standalone version (`php artisan vendor:publish --tag=mcp-views`); ours reuses the app's auth layout and Flux components so it looks like the login page.

## 07a — How the flow works

### Demo script

1. **The pain (30 seconds).** Remind viewers of lesson 01: create a token in Settings, copy it, paste it into a terminal command. Customers won't do that, and a token that never expires is a liability.
2. **The result first.**
   ```bash
   claude mcp add --transport http --scope local teamboard-alice \
     https://teamboard.test/mcp/workspaces/acme
   export NODE_EXTRA_CA_CERTS="$HOME/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem"
   claude
   ```
   - No `--header` this time. Just the URL.
   - `/mcp` shows that `teamboard-alice` needs authentication. Pick it and choose **Authenticate**.
   - The browser opens TeamBoard's login page. Log in as Alice.
   - The consent screen shows "Authorize …" with the client's name, **Logged in as alice@example.com**, and where the browser will be sent back (`http://localhost`). Click **Approve**.
   - Back in Claude Code: connected, with 12 tools. Ask "What tasks do we have in Acme?" It works exactly like before.
3. **Take it apart.** Do what Claude Code did, by hand. Each step is a plain HTTP request.
   1. Call the server without a token:
      ```bash
      curl -i -X POST https://teamboard.test/mcp/workspaces/acme \
        -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
        -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
      ```
      The result is `401` with `WWW-Authenticate: Bearer realm="mcp", resource_metadata="https://teamboard.test/.well-known/oauth-protected-resource/mcp/workspaces/acme"`. That header is how the client knows where to start.
   2. Follow it:
      ```bash
      curl -s https://teamboard.test/.well-known/oauth-protected-resource/mcp/workspaces/acme | jq
      curl -s https://teamboard.test/.well-known/oauth-authorization-server | jq
      ```
      - **Protected resource:** "this URL is protected by the authorization server at `https://teamboard.test` and needs scope `mcp:use`."
      - **Authorization server:** the `authorize`, `token` and `register` endpoints, and `code_challenge_methods_supported: ["S256"]`, which means PKCE is required.
   3. **Dynamic client registration.** Claude Code was never set up in TeamBoard beforehand. It registered itself with `POST /oauth/register`. Show the new row in `oauth_clients`: the name the client sent, a `localhost` redirect URI, no secret (a public client). Explain why there's no secret: a CLI on someone's laptop can't keep one, so PKCE takes its place.
   4. **The browser step:** `/oauth/authorize?client_id=…&code_challenge=…`. The user proves who they are (normal login) and consents. TeamBoard redirects to `http://localhost:<port>/callback?code=…`, where Claude Code is listening.
   5. **Code → tokens.** Claude Code POSTs the code and its PKCE **verifier** to `/oauth/token`. Only the program that created the challenge knows the verifier, so a stolen code is useless. Show `oauth_access_tokens`: `user_id = 1`, `scopes = ["mcp:use"]`, `expires_at` one hour from now. There is also a row in `oauth_refresh_tokens` (30 days). Claude Code refreshes on its own.
   6. From then on, every MCP request carries `Authorization: Bearer <access token>`. The header is the same as in lesson 01, but the token now comes from OAuth.
4. **Revocation.** Open **Settings → MCP access** as Alice in the browser. The token form is gone. The page now lists connected clients. Click **Disconnect**, then ask Claude Code something again: authentication fails (401). Authenticate again in `/mcp` to reconnect.

### Whiteboard summary (optional slide)

```text
Claude Code                         TeamBoard
  POST /mcp/workspaces/acme  ───▶  401 + resource_metadata
  GET  /.well-known/...      ───▶  where to authorize, register, get tokens
  POST /oauth/register       ───▶  client_id (public client, scope mcp:use)
  browser: /oauth/authorize  ───▶  login → consent → redirect with ?code
  POST /oauth/token (+PKCE)  ───▶  access token (1h) + refresh token (30d)
  POST /mcp/... Bearer token ───▶  tools, scoped by membership and policies
```

## 07b — How it's built

### Demo script

1. **Permissions are unchanged.** Connect the lesson 04 users over OAuth and repeat the role check:

   | Server | URL | Log in as | Tools |
   | --- | --- | --- | --- |
   | `teamboard-bob` | `/mcp/workspaces/acme` | Bob | 11 (no `delete_task`) |
   | `teamboard-charlie` | `/mcp/workspaces/acme` | Charlie | 6 |
   | `teamboard-dana-acme` | `/mcp/workspaces/acme` | Dana | 12 |
   | `teamboard-dana-sandbox` | `/mcp/workspaces/sandbox` | Dana | 6 |

   - The browser reuses the TeamBoard session. **Log out in the browser before authenticating as the next user.** The consent screen's **Logged in as** line shows who you're about to approve as. Point this out: it's also what protects real users.
   - Dana's two connections are the same person with two different tool sets. The approval says who she is, and the URL plus her membership decide what she can do.
2. **Audit trail.** "Using teamboard-alice, add a task called 'OAuth audit check' to Website Redesign." Open **MCP audit log**: the entry's actor is Alice, exactly as with tokens.

### Code walkthrough

1. **`composer.json`**: `laravel/passport` in, `laravel/sanctum` out.
2. **`app/Models/User.php`**: `Laravel\Passport\HasApiTokens` replaces Sanctum's trait, and the class implements `OAuthenticatable`.
3. **`config/auth.php`**: a new `api` guard with the `passport` driver. The browser keeps the `web` session guard.
4. **`routes/ai.php`** — the heart of the lesson:
   - `Mcp::oauthRoutes()` registers the two `.well-known` metadata endpoints and `POST /oauth/register`. Passport itself provides `/oauth/authorize` and `/oauth/token`. Show them with `php artisan route:list --path=oauth`.
   - The MCP route's middleware: `auth:sanctum` → `auth:api`. That's the only change. The rate limiter and `ResolveMcpWorkspace` are untouched.
   - Where the 401 header comes from: Laravel MCP's own `AddWwwAuthenticateHeader` middleware. It switches to the OAuth form automatically once `oauthRoutes()` is registered.
5. **`config/mcp.php`**: `redirect_domains` changed from the default `'*'` to `'http://localhost'`. Registration rejects any other redirect, so a random site can't register itself as "Claude Code" and receive codes. Adding `https://claude.ai` here is the step for a web connector later.
6. **`app/Providers/AppServiceProvider.php`** → `configurePassport()`:
   - `Passport::authorizationView('mcp.authorize')` uses our consent screen.
   - Access tokens last 1 hour and refresh tokens 30 days. Passport's default access token lasts a year; short-lived tokens plus refresh is the OAuth 2.1 recommendation.
7. **`resources/views/mcp/authorize.blade.php`**: two small forms that POST (approve) or DELETE (deny) `/oauth/authorize` with the `auth_token` Passport stored in the session. It tells the user plainly that the app will act as them, with their roles, in every workspace.
8. **Why there are no custom scopes.** Laravel MCP advertises one scope, `mcp:use`. It's tempting to invent `tasks:delete`, but Dana is an owner in Acme and a viewer in Sandbox. One token can't carry that, so roles stay in the policies.
9. **`app/Livewire/Settings/McpAccess.php`**: the page now groups the user's non-revoked Passport tokens by client. `disconnect()` revokes every access token and its refresh token for that client in one transaction. Another user's client ID is a 404, just like foreign tasks.
10. **The removed parts**: the token form, `config/sanctum.php`, and the `personal_access_tokens` table (dropped by a new migration, so the lesson 06 tag still works as it did).

### Tests to show

```bash
php artisan test --compact tests/Feature/McpOAuthTest.php tests/Feature/McpEndpointTest.php tests/Feature/Settings/McpAccessTest.php
```

- **The whole OAuth flow in-process** (`McpOAuthTest.php`), with no browser and no external service:
  - `advertises OAuth discovery when an MCP request is unauthenticated`: follows the 401 header to both metadata documents.
  - `registers an allowed public MCP OAuth client`, and `rejects MCP OAuth clients with disallowed redirect URIs` (a foreign domain and a custom scheme).
  - `redirects guests to login before showing the consent screen`, `shows the consent screen and issues no code until the user approves`, `denying consent redirects back with an error and grants no access`.
  - `requires a PKCE code challenge from public clients`.
  - `exchanges an authorization code using a valid PKCE verifier`: the real token then calls `list_tasks` and sees only its workspace.
  - `rejects invalid PKCE verifiers and reused authorization codes`.
  - `refreshes an expiring access token and retires the previous one`.
- **The endpoint** (`McpEndpointTest.php`): `returns 401 for missing invalid expired revoked and legacy MCP bearer tokens`. The "legacy" case is an old Sanctum-style `1|…` token.
- **The settings page** (`McpAccessTest.php`): `disconnecting a client revokes its access and refresh tokens`, and `a disconnected client can no longer call the MCP endpoint`.
- **Everything else** (tools, roles, tenants, audit, rate limit) still passes unchanged. Those tests now get a real Passport token from the `mcpAccessToken()` helper in `tests/Pest.php`. Mention that `tests/TestCase.php` generates an RSA key pair in memory, so tests never need the gitignored key files.

Finish with the full suite and static analysis:

```bash
php artisan test --compact
composer types:check
```

## Gotchas

- **Wrong user approved?** The browser reuses whoever is logged in to TeamBoard. Log out between users, and read the **Logged in as** line before clicking Approve.
- **Re-authenticating a server as another user:** remove and re-add it (`claude mcp remove … -s local`, then `claude mcp add …`), or clear its authentication from `/mcp`.
- **TLS:** `NODE_EXTRA_CA_CERTS` must be set for Claude Code, because the token exchange also goes to `https://teamboard.test`.
- **Keys:** `storage/oauth-private.key` and `storage/oauth-public.key` are gitignored. A fresh checkout needs `php artisan passport:keys`, or every token request fails.
- **This works locally because Claude Code runs on your machine.** Claude.ai and Claude Desktop connectors connect from Anthropic's servers, so they need a public HTTPS URL (for example Laravel Cloud) and `https://claude.ai` in `mcp.redirect_domains`. That's a possible follow-up lesson.
