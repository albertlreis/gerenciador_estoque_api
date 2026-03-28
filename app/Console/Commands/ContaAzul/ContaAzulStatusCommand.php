<?php

namespace App\Console\Commands\ContaAzul;

use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulImportBatch;
use Illuminate\Console\Command;

class ContaAzulStatusCommand extends Command
{
    protected $signature = 'conta-azul:status {--loja=}';

    protected $description = 'Mostra status resumido da integração Conta Azul';

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
        $this->info('Conexão: ' . json_encode($c?->only(['id', 'status', 'ultimo_healthcheck_em', 'ultimo_erro']), JSON_UNESCAPED_UNICODE));

        $batches = ContaAzulImportBatch::query()->orderByDesc('id')->limit(5)->get();
        $this->info('Últimos batches: ' . $batches->count());

        return self::SUCCESS;
    }
}
