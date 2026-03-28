<?php

namespace App\Jobs\ContaAzul;

use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ExportacaoContaAzulService;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExportProdutoContaAzulJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $produtoId,
        public readonly ?int $variacaoId = null,
        public readonly ?int $lojaId = null
    ) {
    }

    public function handle(ExportacaoContaAzulService $export, ContaAzulConnectionService $connections): void
    {
        $conexao = $connections->latestForLoja($this->lojaId);
        if (!$conexao) {
            return;
        }

        $produto = Produto::query()->findOrFail($this->produtoId);
        $variacao = $this->variacaoId ? ProdutoVariacao::query()->find($this->variacaoId) : null;

        $export->exportarProduto($conexao, $produto, $variacao, $this->lojaId);
    }
}
