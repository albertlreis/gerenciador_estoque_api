<?php

namespace App\Integrations\ContaAzul\Services;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulSyncLog;
use App\Jobs\ContaAzul\ExportBaixaContaAzulJob;
use App\Jobs\ContaAzul\ExportClienteContaAzulJob;
use App\Jobs\ContaAzul\ExportPedidoContaAzulJob;
use App\Jobs\ContaAzul\ExportProdutoContaAzulJob;
use App\Jobs\ContaAzul\ExportTituloContaAzulJob;

class ContaAzulExportDispatchService
{
    public function __construct(
        private readonly ContaAzulConnectionService $connections
    ) {
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    public function cliente(int $clienteId, ?int $lojaId = null, array $contexto = []): void
    {
        $this->dispatch(
            ContaAzulEntityType::PESSOA,
            $clienteId,
            $lojaId,
            $contexto,
            fn () => ExportClienteContaAzulJob::dispatch($clienteId, $lojaId)
        );
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    public function produto(int $produtoId, ?int $variacaoId = null, ?int $lojaId = null, array $contexto = []): void
    {
        $this->dispatch(
            ContaAzulEntityType::PRODUTO,
            $produtoId,
            $lojaId,
            array_filter($contexto + ['variacao_id' => $variacaoId], fn ($value) => $value !== null),
            fn () => ExportProdutoContaAzulJob::dispatch($produtoId, $variacaoId, $lojaId)
        );
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    public function pedido(int $pedidoId, ?int $lojaId = null, array $contexto = []): void
    {
        $this->dispatch(
            ContaAzulEntityType::VENDA,
            $pedidoId,
            $lojaId,
            $contexto,
            fn () => ExportPedidoContaAzulJob::dispatch($pedidoId, $lojaId)
        );
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    public function titulo(int $contaReceberId, ?int $lojaId = null, array $contexto = []): void
    {
        $this->dispatch(
            ContaAzulEntityType::TITULO,
            $contaReceberId,
            $lojaId,
            $contexto,
            fn () => ExportTituloContaAzulJob::dispatch($contaReceberId, $lojaId)
        );
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    public function baixa(int $pagamentoId, ?int $lojaId = null, array $contexto = []): void
    {
        $this->dispatch(
            ContaAzulEntityType::BAIXA,
            $pagamentoId,
            $lojaId,
            $contexto,
            fn () => ExportBaixaContaAzulJob::dispatch($pagamentoId, $lojaId)
        );
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    private function dispatch(string $tipoEntidade, int $idLocal, ?int $lojaId, array $contexto, callable $jobFactory): void
    {
        if (!filter_var(config('conta_azul.flags.exportacao_ativa', true), FILTER_VALIDATE_BOOL)) {
            $this->log($tipoEntidade, $idLocal, $lojaId, 'ignorado', 'Exportação automática desativada.', $contexto);
            return;
        }

        $conexao = $this->connections->latestForLoja($lojaId);
        if (!$conexao) {
            $this->log($tipoEntidade, $idLocal, $lojaId, 'ignorado', 'Nenhuma conexão Conta Azul encontrada.', $contexto);
            return;
        }

        $conexao->loadMissing('token');
        if ($conexao->token === null) {
            $this->log($tipoEntidade, $idLocal, $lojaId, 'ignorado', 'Conexão Conta Azul sem tokens válidos.', $contexto);
            return;
        }

        $this->log($tipoEntidade, $idLocal, $lojaId, 'enfileirado', null, $contexto);
        $jobFactory()->afterCommit();
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    private function log(
        string $tipoEntidade,
        int $idLocal,
        ?int $lojaId,
        string $status,
        ?string $mensagem,
        array $contexto
    ): void {
        ContaAzulSyncLog::create([
            'loja_id' => $lojaId,
            'tipo_entidade' => $tipoEntidade,
            'id_local' => $idLocal,
            'id_externo' => null,
            'direcao' => 'export',
            'status' => $status,
            'tentativa' => 1,
            'payload_resumo' => json_encode(array_merge(['origem' => 'disparo_automatico'], $contexto), JSON_UNESCAPED_UNICODE),
            'resposta_resumo' => $mensagem,
            'erro_codigo' => null,
            'erro_mensagem' => $mensagem,
            'executado_em' => now(),
        ]);
    }
}
