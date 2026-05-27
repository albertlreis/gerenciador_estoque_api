<?php

namespace App\Console\Commands\ContaAzul;

use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Models\AuditoriaLog;
use Illuminate\Console\Command;

class ContaAzulStatusCommand extends Command
{
    protected $signature = 'conta-azul:status {--loja=}';

    protected $description = 'Mostra status resumido da integracao Conta Azul';

    public function handle(): int
    {
        $loja = $this->option('loja');
        $lojaId = $loja !== null && $loja !== '' ? (int) $loja : null;

        $q = ContaAzulConexao::query()->orderByDesc('id');
        if ($lojaId !== null) {
            $q->where('loja_id', $lojaId);
        } else {
            $q->whereNull('loja_id');
        }

        $c = $q->first();
        $this->info('Conexao: ' . json_encode($c?->only(['id', 'status', 'ultimo_healthcheck_em', 'ultimo_erro']), JSON_UNESCAPED_UNICODE));

        $batches = AuditoriaLog::query()
            ->where('modulo', 'conta_azul')
            ->where('acao', 'import_batch')
            ->orderByDesc('occurred_at')
            ->limit(5)
            ->get();
        $this->info('Ultimos batches: ' . $batches->count());

        return self::SUCCESS;
    }
}
