<?php

namespace App\Console\Commands\ContaAzul;

use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Services\ConciliacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ContaAzulFinanceiroLocalOfficializationService;
use App\Integrations\ContaAzul\Services\ImportacaoContaAzulService;
use App\Integrations\ContaAzul\Support\ContaAzulRuntimeState;
use App\Services\AuditoriaLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class ContaAzulFinanceiroAutoImportCommand extends Command
{
    private const ENTIDADES = [
        'pessoas',
        'contas_financeiras',
        'categorias_financeiras',
        'centros_custo',
        'financeiro',
        'contas_pagar',
        'parcelas',
        'baixas',
        'saldos_contas_financeiras',
        'formas_pagamento',
        'notas',
    ];

    protected $signature = 'conta-azul:financeiro-auto-import
        {--dry-run-only : Importa, concilia e mostra o plano sem oficializar}
        {--skip-officialize : Importa e concilia sem oficializar}
        {--force : Permite execucao manual mesmo com a automacao desligada}
        {--loja= : ID opcional da loja fora de producao}';

    protected $description = 'Executa a importacao financeira automatica da Conta Azul com conciliacao e oficializacao segura';

    /**
     * @var array<string, mixed>
     */
    private array $summary = [];

    public function handle(
        ContaAzulConnectionService $connections,
        ImportacaoContaAzulService $importacao,
        ConciliacaoContaAzulService $conciliacao,
        ContaAzulFinanceiroLocalOfficializationService $officialization,
        AuditoriaLogService $auditoria
    ): int {
        $enabled = (bool) config('conta_azul.auto_finance_import.enabled', false);
        $forced = (bool) $this->option('force');

        if (!$enabled && !$forced) {
            $this->info('Automacao financeira Conta Azul desligada.');
            $this->logRun($auditoria, 'ignorado', 'Automacao financeira Conta Azul desligada por configuracao.');

            return self::SUCCESS;
        }

        $lojaId = $this->lojaId();
        if (app()->environment('production') && $lojaId !== null) {
            $this->error('Em producao, execute sem --loja para usar todo o staging importado.');

            return self::FAILURE;
        }

        $lock = Cache::lock(ContaAzulRuntimeState::AUTO_FINANCE_IMPORT_LOCK_KEY, $this->lockSeconds());
        if (!$lock->get()) {
            $this->warn('Outra importacao financeira Conta Azul ja esta em andamento.');
            $this->logRun($auditoria, 'ignorado', 'Outra importacao financeira Conta Azul ja esta em andamento.');

            return self::SUCCESS;
        }

        $this->summary = [
            'dry_run_only' => (bool) $this->option('dry-run-only'),
            'skip_officialize' => (bool) $this->option('skip-officialize'),
            'force' => $forced,
            'loja_id' => $lojaId,
            'started_at' => now()->toISOString(),
        ];
        $this->logRun($auditoria, 'executando', 'Importacao financeira automatica Conta Azul iniciada.');

        try {
            $conexao = $this->connection($connections, $lojaId);
            $this->healthcheck($connections, $conexao);

            $this->pauseExportsIfConfigured();
            $this->importarEntidades($importacao, $conexao, $lojaId);
            $this->summary['conciliacao'] = $conciliacao->conciliarTudo($lojaId);

            $this->summary['dry_run'] = $officialization->dryRun($lojaId);
            $this->renderSummary('Dry-run oficializacao financeira', $this->summary['dry_run']);

            if ($this->shouldOfficialize()) {
                $this->summary['oficializacao'] = $officialization->oficializar($lojaId);
                $this->renderSummary('Oficializacao financeira', $this->summary['oficializacao']);
                $this->summary['backfill_pessoas'] = $officialization->backfillPessoasFinanceiras($lojaId);
                $this->renderSummary('Backfill pessoas financeiras', $this->summary['backfill_pessoas']);
            } else {
                $this->warn('Oficializacao ignorada por flag/opcao.');
                $this->summary['oficializacao_ignorada'] = true;
            }

            $this->auditSaldos();
            $this->summary['finished_at'] = now()->toISOString();
            $this->logRun($auditoria, 'concluido', 'Importacao financeira automatica Conta Azul concluida.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->summary['failed_at'] = now()->toISOString();
            $this->summary['erro'] = [
                'classe' => get_class($e),
                'mensagem' => $e->getMessage(),
            ];
            $this->logRun($auditoria, 'falha', 'Importacao financeira automatica Conta Azul falhou: ' . $e->getMessage(), 'error');
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            Cache::forget(ContaAzulRuntimeState::EXPORTS_PAUSED_CACHE_KEY);
            optional($lock)->release();
        }
    }

    private function lojaId(): ?int
    {
        $loja = $this->option('loja');

        return $loja !== null && $loja !== '' ? (int) $loja : null;
    }

    private function lockSeconds(): int
    {
        return max(60, (int) config('conta_azul.auto_finance_import.lock_minutes', 360) * 60);
    }

    private function connection(ContaAzulConnectionService $connections, ?int $lojaId): ContaAzulConexao
    {
        $conexao = $connections->operationalForLoja($lojaId);
        if (!$conexao) {
            throw new \RuntimeException('Nenhuma conexao Conta Azul encontrada.');
        }

        return $conexao;
    }

    private function healthcheck(ContaAzulConnectionService $connections, ContaAzulConexao $conexao): void
    {
        if (!$connections->healthcheck($conexao)) {
            throw new \RuntimeException('Healthcheck Conta Azul falhou.');
        }

        $this->summary['healthcheck'] = 'ok';
    }

    private function pauseExportsIfConfigured(): void
    {
        if (!config('conta_azul.auto_finance_import.pause_exports', true)) {
            return;
        }

        $ttl = max(1, (int) config('conta_azul.auto_finance_import.export_pause_ttl_minutes', 360));
        Cache::put(ContaAzulRuntimeState::EXPORTS_PAUSED_CACHE_KEY, [
            'motivo' => 'Importacao financeira automatica Conta Azul em andamento.',
            'started_at' => $this->summary['started_at'] ?? now()->toISOString(),
        ], now()->addMinutes($ttl));

        $this->summary['exports_paused'] = true;
    }

    private function importarEntidades(ImportacaoContaAzulService $importacao, ContaAzulConexao $conexao, ?int $lojaId): void
    {
        $this->summary['imports'] = [];

        foreach (self::ENTIDADES as $entidade) {
            $tipo = ContaAzulImportCommand::ENTIDADES_SUPORTADAS[$entidade];
            $resultado = $importacao->importarParaStaging($conexao, $tipo, $lojaId);
            $this->summary['imports'][$entidade] = $resultado;
            $this->info(sprintf('Importado %s: batch %d, lidos %d', $entidade, $resultado['batch_id'], $resultado['lidos']));
        }
    }

    private function shouldOfficialize(): bool
    {
        if ($this->option('dry-run-only') || $this->option('skip-officialize')) {
            return false;
        }

        if (app()->environment('production')) {
            return (bool) config('conta_azul.auto_finance_import.officialize_enabled', false);
        }

        return (bool) config('conta_azul.auto_finance_import.officialize_enabled', false)
            || (bool) $this->option('force');
    }

    /**
     * @param array<string, array<string, int>> $summary
     */
    private function renderSummary(string $title, array $summary): void
    {
        $this->info($title);
        $rows = [];
        foreach ($summary as $entity => $data) {
            $rows[] = [
                $entity,
                $data['previstos'] ?? $data['criados'] ?? 0,
                $data['atualizados'] ?? 0,
                $data['ignorados'] ?? 0,
                $data['lancamentos'] ?? 0,
            ];
        }

        $this->table(['entidade', 'criados/previstos', 'atualizados', 'ignorados', 'lancamentos'], $rows);
    }

    private function auditSaldos(): void
    {
        Artisan::call('conta-azul:auditar-saldos');
        $this->summary['auditoria_saldos'] = trim(Artisan::output());
    }

    private function logRun(AuditoriaLogService $auditoria, string $status, string $message, string $level = 'info'): void
    {
        $sourceUid = $this->summary['source_uid'] ?? AuditoriaLogService::sourceUid(
            'estoque',
            'conta_azul_financeiro_auto_import',
            'run',
            (string) ($this->summary['started_at'] ?? now()->toISOString())
        );
        $this->summary['source_uid'] = $sourceUid;

        $auditoria->registrar([
            'replace_existing' => true,
            'occurred_at' => now(),
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'nivel' => $level,
            'modulo' => 'conta_azul',
            'acao' => 'financeiro_auto_import',
            'status' => $status,
            'label' => 'Importacao financeira automatica Conta Azul',
            'message' => $message,
            'context_json' => $this->summary,
            'source_system' => 'estoque',
            'source_kind' => 'import_run',
            'source_uid' => $sourceUid,
            'retention_days' => 365,
        ]);
    }
}
