<?php

namespace App\Console\Commands\ContaAzul;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ContaAzulResetFinanceiroOficialCommand extends Command
{
    protected $signature = 'conta-azul:reset-financeiro-oficial
        {--dry-run : Mostra as contagens sem apagar dados}
        {--confirm-production : Confirma execucao em producao quando a flag de producao estiver ativa}';

    protected $description = 'Zera as tabelas financeiras oficiais em producao com confirmacao explicita, preservando staging Conta Azul';

    /**
     * @var array<int, string>
     */
    private array $tables = [
        'lancamentos_financeiros',
        'contas_pagar_pagamentos',
        'contas_receber_pagamentos',
        'despesa_recorrente_execucoes',
        'despesas_recorrentes',
        'transferencias_financeiras',
        'contas_pagar',
        'contas_receber',
        'financeiro_parcelamentos',
        'contas_financeiras',
        'categorias_financeiras',
        'centros_custo',
        'formas_pagamento',
        'notas_fiscais',
        'conta_azul_mapeamentos',
    ];

    public function handle(): int
    {
        if (!$this->allowed()) {
            return self::FAILURE;
        }

        $counts = $this->counts();
        $this->table(['tabela', 'registros'], $counts);

        if ($this->option('dry-run')) {
            $this->info('Dry-run: nenhum dado foi apagado.');

            return self::SUCCESS;
        }

        Schema::disableForeignKeyConstraints();
        try {
            foreach ($this->tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->info('Reset financeiro oficial concluido. Staging Conta Azul preservado.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{0:string,1:int|string}>
     */
    private function counts(): array
    {
        $counts = [];
        foreach ($this->tables as $table) {
            $counts[] = [$table, Schema::hasTable($table) ? (int) DB::table($table)->count() : 'MISSING'];
        }

        return $counts;
    }

    private function allowed(): bool
    {
        if (!app()->environment('production')) {
            $this->error('Comando bloqueado: este reset oficial e permitido apenas em APP_ENV=production.');

            return false;
        }

        if (!$this->option('confirm-production')) {
            $this->error('Comando bloqueado: use --confirm-production para execucao em producao.');

            return false;
        }

        if (!$this->productionFlagEnabled()) {
            $this->error('Comando bloqueado: defina CONTA_AZUL_OFFICIALIZE_PRODUCTION_ENABLED=true somente durante a janela de producao.');

            return false;
        }

        return true;
    }

    private function productionFlagEnabled(): bool
    {
        $value = env('CONTA_AZUL_OFFICIALIZE_PRODUCTION_ENABLED', config('conta_azul.flags.oficializacao_producao_ativa', false));

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
