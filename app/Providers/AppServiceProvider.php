<?php

namespace App\Providers;

use App\Repositories\Contracts\ContaPagarRepository;
use App\Repositories\Eloquent\ContaPagarRepositoryEloquent;
use App\Support\Audit\AuditContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $this->app->singleton(AuditContext::class, fn () => new AuditContext());
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Schema::defaultStringLength(191);

        // 🔤 Define charset e collation padrão para todas as migrations
        config([
            'database.connections.mysql.charset' => 'utf8mb4',
            'database.connections.mysql.collation' => 'utf8mb4_0900_ai_ci',
        ]);

        // ✅ Garante que futuras migrations usem o charset/collation correto
        DB::statement("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_0900_ai_ci'");

        require_once app_path('Helpers/AuditoriaHelper.php');
    }
}
