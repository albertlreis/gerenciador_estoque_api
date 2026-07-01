<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            $defaultLimit = app()->environment('local') ? 600 : 60;
            $perMinute = (int) env('API_RATE_LIMIT_PER_MINUTE', $defaultLimit);

            return Limit::perMinute(max(1, $perMinute))->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('client-logs', function (Request $request) {
            $defaultLimit = app()->environment('local') ? 120 : 30;
            $perMinute = (int) env('CLIENT_LOG_RATE_LIMIT_PER_MINUTE', $defaultLimit);

            return Limit::perMinute(max(1, $perMinute))->by($request->ip());
        });
    }
}
