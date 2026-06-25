<?php

namespace App\Integrations\ContaAzul\Services;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Jobs\ContaAzul\ExportBaixaContaPagarContaAzulJob;
use App\Jobs\ContaAzul\ExportBaixaContaAzulJob;
use App\Jobs\ContaAzul\ExportContaPagarContaAzulJob;
use App\Jobs\ContaAzul\ExportClienteContaAzulJob;
use App\Jobs\ContaAzul\ExportPedidoContaAzulJob;
use App\Jobs\ContaAzul\ExportProdutoContaAzulJob;
use App\Jobs\ContaAzul\ExportTituloContaAzulJob;
use App\Models\ContaPagarPagamento;
use App\Models\ContaReceberPagamento;
use App\Services\AuditoriaLogService;
use Illuminate\Support\Facades\Bus;

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
    public function contaPagar(int $contaPagarId, ?int $lojaId = null, array $contexto = []): void
    {
        $this->dispatch(
            ContaAzulEntityType::CONTA_PAGAR,
            $contaPagarId,
            $lojaId,
            $contexto,
            fn () => ExportContaPagarContaAzulJob::dispatch($contaPagarId, $lojaId)
        );
    }

    /**
     * @param  array<int, int>  $pagamentoIds
     * @param  array<string, mixed>  $contexto
     */
    public function contaPagarComBaixas(int $contaPagarId, array $pagamentoIds, ?int $lojaId = null, array $contexto = []): void
    {
        $this->dispatch(
            ContaAzulEntityType::CONTA_PAGAR,
            $contaPagarId,
            $lojaId,
            array_filter($contexto + [
                'pagamentos_conta_pagar_ids' => array_values($pagamentoIds),
            ], fn ($value) => $value !== null && $value !== []),
            fn () => Bus::chain(array_merge(
                [(new ExportContaPagarContaAzulJob($contaPagarId, $lojaId))->afterCommit()],
                array_map(
                    fn (int $pagamentoId) => new ExportBaixaContaPagarContaAzulJob($pagamentoId, $lojaId),
                    array_values($pagamentoIds)
                )
            ))->dispatch()
        );
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    public function baixa(int $pagamentoId, ?int $lojaId = null, array $contexto = []): void
    {
        $pagamento = ContaReceberPagamento::query()
            ->select(['id', 'conta_receber_id'])
            ->find($pagamentoId);
        $contaReceberId = $pagamento?->conta_receber_id !== null
            ? (int) $pagamento->conta_receber_id
            : null;
        $tituloSemMapeamento = $contaReceberId !== null
            && !ContaAzulMapeamento::idExternoPorLocal(ContaAzulEntityType::TITULO, $contaReceberId, $lojaId);

        $this->dispatch(
            ContaAzulEntityType::BAIXA,
            $pagamentoId,
            $lojaId,
            array_filter($contexto + [
                'conta_receber_id' => $contaReceberId,
                'titulo_dependente' => $tituloSemMapeamento ? true : null,
            ], fn ($value) => $value !== null),
            fn () => $tituloSemMapeamento && $contaReceberId !== null
                ? $this->dispatchBaixaDepoisDoTitulo($contaReceberId, $pagamentoId, $lojaId)
                : ExportBaixaContaAzulJob::dispatch($pagamentoId, $lojaId)
        );
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    public function baixaContaPagar(int $pagamentoId, ?int $lojaId = null, array $contexto = []): void
    {
        $pagamento = ContaPagarPagamento::query()
            ->select(['id', 'conta_pagar_id'])
            ->find($pagamentoId);
        $contaPagarId = $pagamento?->conta_pagar_id !== null
            ? (int) $pagamento->conta_pagar_id
            : null;
        $contaSemMapeamento = $contaPagarId !== null
            && !ContaAzulMapeamento::idExternoPorLocal(ContaAzulEntityType::CONTA_PAGAR, $contaPagarId, $lojaId);

        $this->dispatch(
            ContaAzulEntityType::BAIXA_CONTA_PAGAR,
            $pagamentoId,
            $lojaId,
            array_filter($contexto + [
                'conta_pagar_id' => $contaPagarId,
                'conta_pagar_dependente' => $contaSemMapeamento ? true : null,
            ], fn ($value) => $value !== null),
            fn () => $contaSemMapeamento && $contaPagarId !== null
                ? $this->dispatchBaixaContaPagarDepoisDaConta($contaPagarId, $pagamentoId, $lojaId)
                : ExportBaixaContaPagarContaAzulJob::dispatch($pagamentoId, $lojaId)
        );
    }

    private function dispatchBaixaDepoisDoTitulo(int $contaReceberId, int $pagamentoId, ?int $lojaId): void
    {
        Bus::chain([
            (new ExportTituloContaAzulJob($contaReceberId, $lojaId))->afterCommit(),
            new ExportBaixaContaAzulJob($pagamentoId, $lojaId),
        ])->dispatch();
    }

    private function dispatchBaixaContaPagarDepoisDaConta(int $contaPagarId, int $pagamentoId, ?int $lojaId): void
    {
        Bus::chain([
            (new ExportContaPagarContaAzulJob($contaPagarId, $lojaId))->afterCommit(),
            new ExportBaixaContaPagarContaAzulJob($pagamentoId, $lojaId),
        ])->dispatch();
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

        if ($conexao->status !== 'ativa') {
            $this->log($tipoEntidade, $idLocal, $lojaId, 'ignorado', 'ConexÃ£o Conta Azul inativa ou em erro.', $contexto + [
                'conexao_id' => $conexao->id,
                'status_conexao' => $conexao->status,
            ]);
            return;
        }

        $conexao->loadMissing('token');
        if ($conexao->token === null) {
            $this->log($tipoEntidade, $idLocal, $lojaId, 'ignorado', 'Conexão Conta Azul sem tokens válidos.', $contexto);
            return;
        }

        if ($conexao->token->isAccessTokenExpired() && !$conexao->token->refresh_token) {
            $this->log($tipoEntidade, $idLocal, $lojaId, 'ignorado', 'Conexao Conta Azul com access token expirado e sem refresh token.', $contexto + [
                'conexao_id' => $conexao->id,
            ]);
            return;
        }

        $this->log($tipoEntidade, $idLocal, $lojaId, 'enfileirado', null, $contexto);
        $pending = $jobFactory();
        if (is_object($pending) && method_exists($pending, 'afterCommit')) {
            $pending->afterCommit();
        }
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
        $payloadResumo = json_encode(array_merge(['origem' => 'disparo_automatico'], $contexto), JSON_UNESCAPED_UNICODE);

        app(AuditoriaLogService::class)->registrar([
            'occurred_at' => now(),
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'nivel' => $status === 'falha' ? 'error' : 'info',
            'modulo' => 'conta_azul',
            'acao' => 'export',
            'status' => $status,
            'label' => 'Log de sincronizacao Conta Azul',
            'message' => $mensagem,
            'entity_type' => $tipoEntidade,
            'entity_id' => $idLocal,
            'context_json' => [
                'loja_id' => $lojaId,
                'tipo_entidade' => $tipoEntidade,
                'id_local' => $idLocal,
                'id_externo' => null,
                'direcao' => 'export',
                'tentativa' => 1,
                'payload_resumo' => $payloadResumo,
                'resposta_resumo' => $mensagem,
                'erro_codigo' => null,
                'erro_mensagem' => $mensagem,
            ],
            'source_system' => 'estoque',
            'source_kind' => 'sync',
            'retention_days' => 365,
        ]);
    }
}
