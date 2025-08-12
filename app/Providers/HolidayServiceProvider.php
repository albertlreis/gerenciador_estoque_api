<?php
namespace App\Providers;

use App\Services\Holidays\Providers\InvertextoHolidayProvider;
use Illuminate\Support\ServiceProvider;
use App\Services\Holidays\Providers\BrasilApiHolidayProvider;
use App\Services\Holidays\Providers\NagerHolidayProvider;
use App\Services\Holidays\HolidaySyncService;

class HolidayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Providers nacionais de acordo com config
        $this->app->bind('holidays.nacional.primary', function () {
            $primary = config('holidays.providers_nacionais.0', 'brasilapi');
            return match ($primary) {
                'nager'     => new NagerHolidayProvider(),
                default     => new BrasilApiHolidayProvider(),
            };
        });

        $this->app->bind('holidays.nacional.fallback', function () {
            $fallback = config('holidays.providers_nacionais.1', 'nager');
            return match ($fallback) {
                'brasilapi' => new BrasilApiHolidayProvider(),
                default     => new NagerHolidayProvider(),
            };
        });

        // Provider estadual
        $this->app->bind('holidays.estadual', function () {
            return new InvertextoHolidayProvider();
        });

        // Orquestrador
        $this->app->singleton(HolidaySyncService::class, function ($app) {
            return new HolidaySyncService(
                $app->make('holidays.nacional.primary'),
                $app->make('holidays.nacional.fallback'),
                $app->make('holidays.estadual'),
            );
        });
    }
}
