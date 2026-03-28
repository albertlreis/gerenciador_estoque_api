<?php

namespace App\Jobs\ContaAzul;

use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ExportacaoContaAzulService;
use App\Models\Pedido;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExportPedidoContaAzulJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $pedidoId,
        public readonly ?int $lojaId = null
    ) {
    }

    public function handle(ExportacaoContaAzulService $export, ContaAzulConnectionService $connections): void
    {
        $conexao = $connections->latestForLoja($this->lojaId);
        if (!$conexao) {
            return;
        }

        $pedido = Pedido::query()->findOrFail($this->pedidoId);
        $export->exportarPedido($conexao, $pedido, $this->lojaId);
    }
}
