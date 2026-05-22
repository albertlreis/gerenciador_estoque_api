<?php

namespace App\Console\Commands\ContaAzul;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ContaAzulResetFinanceiroLocalCommand extends Command
{
    protected $signature = 'conta-azul:reset-financeiro-local {--dry-run : Mostra as contagens sem apagar dados}';

    protected $description = 'Zera as tabelas financeiras oficiais somente no ambiente local, preservando staging Conta Azul';

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

        $this->info('Reset financeiro local concluido. Staging Conta Azul preservado.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{0:string,1:int}>
     */
    private function counts(): array
    {
        $counts = [];
        foreach ($this->tables as $table) {
            $counts[] = [$table, Schema::hasTable($table) ? (int) DB::table($table)->count() : 0];
        }

        return $counts;
    }

    private function allowed(): bool
    {
        if (!app()->environment('local')) {
            $this->error('Comando bloqueado: permitido apenas em APP_ENV=local.');
            return false;
        }

        if (!config('conta_azul.flags.oficializacao_ativa')) {
            $this->error('Comando bloqueado: defina CONTA_AZUL_OFFICIALIZE_ENABLED=true no ambiente local.');
            return false;
        }

        return true;
    }
}
