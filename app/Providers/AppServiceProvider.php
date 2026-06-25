<?php

namespace App\Providers;

use App\Integrations\ContaAzul\Auth\ContaAzulOAuthService;
use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use App\Integrations\ContaAzul\Import\CategoriaFinanceiraContaAzulImportAdapter;
use App\Integrations\ContaAzul\Import\CentroCustoContaAzulImportAdapter;
use App\Integrations\ContaAzul\Import\ContaFinanceiraContaAzulImportAdapter;
use App\Integrations\ContaAzul\Import\ContaPagarContaAzulImportAdapter;
use App\Integrations\ContaAzul\Import\NotaContaAzulImportAdapter;
use App\Integrations\ContaAzul\Import\PessoaContaAzulImportAdapter;
use App\Integrations\ContaAzul\Import\ProdutoContaAzulImportAdapter;
use App\Integrations\ContaAzul\Import\TituloContaAzulImportAdapter;
use App\Integrations\ContaAzul\Import\VendaContaAzulImportAdapter;
use App\Integrations\ContaAzul\Mappers\ContaAzulBaixaMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulCobrancaMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulPedidoMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulPessoaMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulProdutoMapper;
use App\Integrations\ContaAzul\Mappers\ContaAzulTituloMapper;
use App\Integrations\ContaAzul\Services\ConciliacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ContaAzulAutoMatchService;
use App\Integrations\ContaAzul\Services\ContaAzulCobrancaService;
use App\Integrations\ContaAzul\Services\ContaAzulLocalCreationService;
use App\Integrations\ContaAzul\Services\ExportacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ImportacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ReconciliacaoContaAzulService;
use App\Integrations\Bancos\BancoDoBrasil\BancoDoBrasilExtratosClient;
use App\Integrations\GoogleCalendar\Auth\GoogleCalendarOAuthService;
use App\Integrations\GoogleCalendar\Clients\GoogleCalendarClient;
use App\Integrations\GoogleCalendar\Services\GoogleCalendarConnectionService;
use App\Integrations\GoogleCalendar\Services\GoogleCalendarEventService;
use App\Repositories\Contracts\ContaPagarRepository;
use App\Repositories\Eloquent\ContaPagarRepositoryEloquent;
use App\Services\FinanceiroLedgerService;
use App\Services\AuditoriaLogService;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
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

        $this->app->singleton(BancoDoBrasilExtratosClient::class, function ($app) {
            return new BancoDoBrasilExtratosClient($app['config']->get('banco_do_brasil.extratos', []));
        });

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
        $this->app->singleton(ContaAzulCobrancaMapper::class, fn () => new ContaAzulCobrancaMapper());
        $this->app->singleton(PessoaContaAzulImportAdapter::class, fn () => new PessoaContaAzulImportAdapter());
        $this->app->singleton(ProdutoContaAzulImportAdapter::class, fn () => new ProdutoContaAzulImportAdapter());
        $this->app->singleton(VendaContaAzulImportAdapter::class, fn () => new VendaContaAzulImportAdapter());
        $this->app->singleton(TituloContaAzulImportAdapter::class, fn () => new TituloContaAzulImportAdapter());
        $this->app->singleton(ContaPagarContaAzulImportAdapter::class, fn () => new ContaPagarContaAzulImportAdapter());
        $this->app->singleton(NotaContaAzulImportAdapter::class, fn () => new NotaContaAzulImportAdapter());
        $this->app->singleton(ContaFinanceiraContaAzulImportAdapter::class, fn () => new ContaFinanceiraContaAzulImportAdapter());
        $this->app->singleton(CategoriaFinanceiraContaAzulImportAdapter::class, fn () => new CategoriaFinanceiraContaAzulImportAdapter());
        $this->app->singleton(CentroCustoContaAzulImportAdapter::class, fn () => new CentroCustoContaAzulImportAdapter());

        $this->app->singleton(ImportacaoContaAzulService::class, function ($app) {
            return new ImportacaoContaAzulService(
                $app['config']->get('conta_azul', []),
                $app->make(ContaAzulConnectionService::class),
                $app->make(ContaAzulClient::class),
                [
                    $app->make(PessoaContaAzulImportAdapter::class),
                    $app->make(ProdutoContaAzulImportAdapter::class),
                    $app->make(VendaContaAzulImportAdapter::class),
                    $app->make(TituloContaAzulImportAdapter::class),
                    $app->make(ContaPagarContaAzulImportAdapter::class),
                    $app->make(NotaContaAzulImportAdapter::class),
                    $app->make(ContaFinanceiraContaAzulImportAdapter::class),
                    $app->make(CategoriaFinanceiraContaAzulImportAdapter::class),
                    $app->make(CentroCustoContaAzulImportAdapter::class),
                ]
            );
        });

        $this->app->singleton(ContaAzulAutoMatchService::class, fn ($app) => new ContaAzulAutoMatchService(
            $app->make(FinanceiroLedgerService::class)
        ));
        $this->app->singleton(ContaAzulLocalCreationService::class, fn ($app) => new ContaAzulLocalCreationService(
            $app->make(ContaAzulAutoMatchService::class),
            $app->make(FinanceiroLedgerService::class)
        ));

        $this->app->singleton(ConciliacaoContaAzulService::class, function ($app) {
            return new ConciliacaoContaAzulService($app->make(ContaAzulAutoMatchService::class));
        });

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

        $this->app->singleton(ContaAzulCobrancaService::class, function ($app) {
            return new ContaAzulCobrancaService(
                $app['config']->get('conta_azul', []),
                $app->make(ContaAzulConnectionService::class),
                $app->make(ContaAzulClient::class),
                $app->make(ExportacaoContaAzulService::class),
                $app->make(ContaAzulCobrancaMapper::class),
                $app->make(AuditoriaLogService::class)
            );
        });

        $this->app->singleton(ReconciliacaoContaAzulService::class, function ($app) {
            return new ReconciliacaoContaAzulService(
                $app->make(ImportacaoContaAzulService::class),
                $app->make(ConciliacaoContaAzulService::class)
            );
        });

        $this->app->singleton(GoogleCalendarClient::class, function ($app) {
            return new GoogleCalendarClient($app['config']->get('google_calendar', []));
        });

        $this->app->singleton(GoogleCalendarOAuthService::class, function ($app) {
            return new GoogleCalendarOAuthService($app['config']->get('google_calendar', []));
        });

        $this->app->singleton(GoogleCalendarConnectionService::class, function ($app) {
            return new GoogleCalendarConnectionService(
                $app['config']->get('google_calendar', []),
                $app->make(GoogleCalendarOAuthService::class),
                $app->make(GoogleCalendarClient::class)
            );
        });

        $this->app->singleton(GoogleCalendarEventService::class, function ($app) {
            return new GoogleCalendarEventService(
                $app['config']->get('google_calendar', []),
                $app->make(GoogleCalendarConnectionService::class),
                $app->make(GoogleCalendarClient::class)
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
        $this->ensureWritablePaths();

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

    private function ensureWritablePaths(): void
    {
        $filesystem = new Filesystem();

        foreach ([
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ] as $path) {
            if (!$filesystem->isDirectory($path)) {
                $filesystem->makeDirectory($path, 0775, true);
            }
        }
    }
}
