<?php

namespace App\Jobs\ContaAzul;

use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ExportacaoContaAzulService;
use App\Models\Cliente;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExportClienteContaAzulJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $clienteId,
        public readonly ?int $lojaId = null
    ) {
    }

    public function handle(ExportacaoContaAzulService $export, ContaAzulConnectionService $connections): void
    {
        $conexao = $connections->latestForLoja($this->lojaId);
        if (!$conexao) {
            return;
        }

        $export->exportarCliente($conexao, Cliente::query()->findOrFail($this->clienteId), $this->lojaId);
    }
}
