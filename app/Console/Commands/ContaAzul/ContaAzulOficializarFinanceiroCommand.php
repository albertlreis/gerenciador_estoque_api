<?php

namespace App\Console\Commands\ContaAzul;

use App\Integrations\ContaAzul\Services\ContaAzulFinanceiroLocalOfficializationService;
use Illuminate\Console\Command;

class ContaAzulOficializarFinanceiroCommand extends Command
{
    protected $signature = 'conta-azul:oficializar-financeiro
        {--dry-run : Mostra o plano sem gravar dados}
        {--backfill-pessoas : Preenche cliente/fornecedor em titulos financeiros ja oficializados}
        {--loja= : ID opcional da loja}
        {--confirm-production : Confirma execucao em producao quando a flag de producao estiver ativa}';

    protected $description = 'Oficializa dados financeiros importados da Conta Azul em ambiente permitido';

    public function handle(ContaAzulFinanceiroLocalOfficializationService $service): int
    {
        if (!$this->allowed()) {
            return self::FAILURE;
        }

        $loja = $this->option('loja');
        $lojaId = $loja !== null && $loja !== '' ? (int) $loja : null;
        if (app()->environment('production') && $lojaId !== null) {
            $this->error('Em producao, execute sem --loja para usar todo o staging importado.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $summary = $service->dryRun($lojaId);
        } elseif ($this->option('backfill-pessoas')) {
            $summary = $service->backfillPessoasFinanceiras($lojaId);
        } else {
            $summary = $service->oficializar($lojaId);
        }

        $this->table(['entidade', 'criados/previstos', 'atualizados', 'ignorados', 'lancamentos'], $this->rows($summary));
        $this->info($this->option('dry-run') ? 'Dry-run: nenhum dado foi gravado.' : 'Operacao financeira Conta Azul concluida.');

        return self::SUCCESS;
    }

    /**
     * @param array<string, array<string, int>> $summary
     * @return array<int, array<int, string|int>>
     */
    private function rows(array $summary): array
    {
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

        return $rows;
    }

    private function allowed(): bool
    {
        if (app()->environment('local')) {
            if (!config('conta_azul.flags.oficializacao_ativa')) {
                $this->error('Comando bloqueado: defina CONTA_AZUL_OFFICIALIZE_ENABLED=true no ambiente local.');

                return false;
            }

            return true;
        }

        if (app()->environment('production')) {
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

        $this->error('Comando bloqueado: ambiente nao permitido para oficializacao Conta Azul.');

        return false;
    }

    private function productionFlagEnabled(): bool
    {
        $value = env('CONTA_AZUL_OFFICIALIZE_PRODUCTION_ENABLED', config('conta_azul.flags.oficializacao_producao_ativa', false));

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
