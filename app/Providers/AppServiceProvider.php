<?php

namespace App\Providers;

use App\Integrations\ContaAzul\Auth\ContaAzulOAuthService;
use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use App\Integrations\ContaAzul\Mappers\ContaAzulBaixaMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulPedidoMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulPessoaMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulProdutoMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulTituloMapper;
use App\Integrations\ContaAzul\Services\ConciliacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ExportacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ImportacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ReconciliacaoContaAzulService;
use App\Repositories\Contracts\ContaPagarRepository;
use App\Repositories\Eloquent\ContaPagarRepositoryEloquent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $this->app->singleton(ContaAzulClient::class, function ($app) {
            return new ContaAzulClient($app['config']->get('conta_azul', []));
        });

        $this->app->singleton(ContaAzulOAuthService::class, function ($app) {
            return new ContaAzulOAuthService($app['config']->get('conta_azul', []));
        });

        $this->app->singleton(ContaAzulConnectionService::class, function ($app) {
            return new ContaAzulConnectionService(
                $app['config']->get('conta_azul', []),
                $app->make(ContaAzulOAuthService::class),
                $app->make(ContaAzulClient::class)
            );
        });

        $this->app->singleton(ContaAzulPessoaMapper::class, fn () => new ContaAzulPessoaMapper());
        $this->app->singleton(ContaAzulProdutoMapper::class, fn () => new ContaAzulProdutoMapper());
        $this->app->singleton(ContaAzulPedidoMapper::class, fn () => new ContaAzulPedidoMapper());
        $this->app->singleton(ContaAzulTituloMapper::class, fn () => new ContaAzulTituloMapper());
        $this->app->singleton(ContaAzulBaixaMapper::class, fn () => new ContaAzulBaixaMapper());

        $this->app->singleton(ImportacaoContaAzulService::class, function ($app) {
            return new ImportacaoContaAzulService(
                $app['config']->get('conta_azul', []),
                $app->make(ContaAzulConnectionService::class),
                $app->make(ContaAzulClient::class)
            );
        });

        $this->app->singleton(ConciliacaoContaAzulService::class, fn () => new ConciliacaoContaAzulService());

        $this->app->singleton(ExportacaoContaAzulService::class, function ($app) {
            return new ExportacaoContaAzulService(
                $app['config']->get('conta_azul', []),
                $app->make(ContaAzulConnectionService::class),
                $app->make(ContaAzulClient::class),
                $app->make(ContaAzulPessoaMapper::class),
                $app->make(ContaAzulProdutoMapper::class),
                $app->make(ContaAzulPedidoMapper::class),
                $app->make(ContaAzulTituloMapper::class),
                $app->make(ContaAzulBaixaMapper::class)
            );
        });

        $this->app->singleton(ReconciliacaoContaAzulService::class, function ($app) {
            return new ReconciliacaoContaAzulService(
                $app->make(ImportacaoContaAzulService::class),
                $app->make(ConciliacaoContaAzulService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Schema::defaultStringLength(191);

        config([
            'database.connections.mysql.charset' => 'utf8mb4',
            'database.connections.mysql.collation' => 'utf8mb4_0900_ai_ci',
        ]);

        try {
            DB::connection()->getPdo();
            DB::statement("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_0900_ai_ci'");
        } catch (QueryException|\PDOException $e) {
            if (!$this->app->environment('testing')) {
                Log::warning('Não foi possível aplicar charset/collation MySQL no boot.', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        require_once app_path('Helpers/AuditoriaHelper.php');
    }
}
