<?php

namespace App\Providers;

use App\Models\Organisation;
use App\Services\Activation\ActivationProvider;
use App\Services\Activation\FakeActivationProvider;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The assessment uses a deterministic fake provider; a real
        // integration would swap this binding for an HTTP-backed one.
        $this->app->bind(ActivationProvider::class, FakeActivationProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureApiRateLimiting();
        $this->configureRequestMacros();
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

    /**
     * Register the default rate limiter for the "api" middleware group.
     */
    protected function configureApiRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });
    }

    /**
     * Register request helpers used by the portal API.
     */
    protected function configureRequestMacros(): void
    {
        Request::macro('organisation', function (): ?Organisation {
            /** @var Request $this */
            return $this->attributes->get('organisation');
        });
    }
}
