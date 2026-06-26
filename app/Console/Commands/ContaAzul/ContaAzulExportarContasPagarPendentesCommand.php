<?php

namespace App\Console\Commands\ContaAzul;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Integrations\ContaAzul\Services\ContaAzulExportDispatchService;
use App\Models\ContaPagar;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ContaAzulExportarContasPagarPendentesCommand extends Command
{
    protected $signature = 'conta-azul:exportar-contas-pagar-pendentes
        {--id= : ID local da conta a pagar}
        {--desde= : Data minima de criacao/vencimento no formato YYYY-MM-DD}
        {--loja-id= : Loja/conexao Conta Azul}
        {--dry-run : Lista o que seria enfileirado sem disparar jobs}
        {--force : Reenfileira mesmo quando ja existe mapeamento Conta Azul}';

    protected $description = 'Enfileira exportacao de contas a pagar pendentes para a Conta Azul.';

    public function handle(ContaAzulExportDispatchService $exports): int
    {
        $id = $this->option('id');
        $desde = $this->option('desde');
        $lojaId = $this->option('loja-id') !== null ? (int) $this->option('loja-id') : null;
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if ($desde !== null) {
            try {
                $desde = Carbon::parse((string) $desde)->toDateString();
            } catch (\Throwable) {
                $this->error('Opcao --desde deve estar no formato YYYY-MM-DD.');
                return self::FAILURE;
            }
        }

        $query = ContaPagar::query()
            ->with('pagamentos:id,conta_pagar_id')
            ->when($id !== null, fn (Builder $q) => $q->whereKey((int) $id))
            ->when($desde !== null, function (Builder $q) use ($desde): void {
                $q->where(function (Builder $inner) use ($desde): void {
                    $inner->whereDate('created_at', '>=', $desde)
                        ->orWhereDate('data_vencimento', '>=', $desde);
                });
            })
            ->orderBy('id');

        $total = 0;
        $enfileiradas = 0;
        $ignoradas = 0;

        $query->chunkById(100, function ($contas) use ($exports, $lojaId, $dryRun, $force, &$total, &$enfileiradas, &$ignoradas): void {
            foreach ($contas as $conta) {
                $total++;
                $mapped = ContaAzulMapeamento::idExternoPorLocal(
                    ContaAzulEntityType::CONTA_PAGAR,
                    (int) $conta->id,
                    $lojaId
                );

                if ($mapped && !$force) {
                    $ignoradas++;
                    $this->line("ignorada conta_pagar #{$conta->id}: ja mapeada ({$mapped})");
                    continue;
                }

                $pagamentoIds = $conta->pagamentos
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();

                $acao = $pagamentoIds === []
                    ? 'exportar conta'
                    : 'exportar conta + baixas #' . implode(',', $pagamentoIds);

                if ($dryRun) {
                    $this->line("dry-run conta_pagar #{$conta->id}: {$acao}");
                    $enfileiradas++;
                    continue;
                }

                if ($pagamentoIds === []) {
                    $exports->contaPagar((int) $conta->id, $lojaId, ['evento' => 'backfill_conta_pagar']);
                } else {
                    $exports->contaPagarComBaixas((int) $conta->id, $pagamentoIds, $lojaId, ['evento' => 'backfill_conta_pagar_com_baixas']);
                }
                $enfileiradas++;
            }
        });

        $this->info("contas analisadas={$total}; enfileiradas={$enfileiradas}; ignoradas={$ignoradas}; dry_run=" . ($dryRun ? 'sim' : 'nao'));

        return self::SUCCESS;
    }
}

