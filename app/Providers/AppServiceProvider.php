<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configurePassport();
    }

    /**
     * Show TeamBoard's own consent screen and keep access tokens short-lived.
     * MCP clients use the refresh token to stay connected.
     */
    protected function configurePassport(): void
    {
        Passport::authorizationView('mcp.authorize');

        Passport::tokensExpireIn(CarbonInterval::hour());
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));
    }

    /**
     * Limit MCP requests per authenticated user. The key is the user alone,
     * not the workspace or token, so every workspace endpoint and every
     * token of one user share a single budget.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('mcp', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(config()->integer('teamboard.mcp_rate_limit_per_minute'))
                ->by($user instanceof User ? 'user:'.$user->id : 'ip:'.$request->ip());
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
