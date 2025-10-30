<?php

namespace App\Providers;

use App\Repositories\Contracts\ContaPagarRepository;
use App\Repositories\Eloquent\ContaPagarRepositoryEloquent;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(ContaPagarRepository::class, ContaPagarRepositoryEloquent::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        require_once app_path('Helpers/AuditoriaHelper.php');
    }
}
