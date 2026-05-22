<?php

namespace App\Console\Commands\ContaAzul;

use App\Integrations\ContaAzul\Services\ContaAzulFinanceiroLocalOfficializationService;
use Illuminate\Console\Command;

class ContaAzulOficializarFinanceiroCommand extends Command
{
    protected $signature = 'conta-azul:oficializar-financeiro {--dry-run : Mostra o plano sem gravar dados} {--loja= : ID opcional da loja}';

    protected $description = 'Oficializa dados financeiros importados da Conta Azul somente no ambiente local';

    public function handle(ContaAzulFinanceiroLocalOfficializationService $service): int
    {
        if (!$this->allowed()) {
            return self::FAILURE;
        }

        $loja = $this->option('loja');
        $lojaId = $loja !== null && $loja !== '' ? (int) $loja : null;

        $summary = $this->option('dry-run')
            ? $service->dryRun($lojaId)
            : $service->oficializar($lojaId);

        $this->table(['entidade', 'criados/previstos', 'atualizados', 'ignorados', 'lancamentos'], $this->rows($summary));
        $this->info($this->option('dry-run') ? 'Dry-run: nenhum dado foi gravado.' : 'Oficializacao financeira local concluida.');

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
