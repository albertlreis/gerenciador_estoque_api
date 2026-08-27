<?php

namespace App\Services;

use App\Enums\PedidoStatus;
use App\Helpers\AuthHelper;
use App\Http\Requests\StorePedidoRequest;
use App\Models\Carrinho;
use App\Models\CarrinhoItem;
use App\Models\PedidoItem;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Caso de uso de finalização de pedido.
 *
 * Orquestra:
 * - Validação de depósitos/estoque para reserva;
 * - Criação do Pedido + Itens + Status;
 * - Criação de registros de Consignação (quando aplicável);
 * - Reserva automática de estoque;
 * - Definição de data limite;
 * - Finalização do carrinho.
 */
final class FinalizarPedidoService
{
    /**
     * @param PedidoFactory                $pedidoFactory         Criação de pedido/itens/status.
     * @param ConsignacaoFactory           $consignacaoFactory    Criação de consignações por item.
     * @param PedidoPrazoService           $prazoService          Cálculo/definição de data limite.
     * @param DepositoResolver             $resolver              Resolve depósito por item (mapa > item).
     * @param EntregaProdutoService        $entregaProdutoService Fluxo central de demanda e reserva.
     */
    public function __construct(
        private readonly PedidoFactory $pedidoFactory,
        private readonly ConsignacaoFactory $consignacaoFactory,
        private readonly PedidoPrazoService $prazoService,
        private readonly DepositoResolver $resolver,
        private readonly EntregaProdutoService $entregaProdutoService,
        private readonly AuditLogger $auditLogger,
        private readonly OutletCatalogoPricingService $outletPricing,
    ) {}

    /**
     * Executa a finalização do pedido a partir de um carrinho existente.
     *
     * Espera-se que a StorePedidoRequest (já validada) contenha:
     * - id_carrinho, id_cliente, (opcional) id_parceiro, observacoes
     * - (opcional) modo_consignacao: bool
     * - (se modo_consignacao) prazo_consignacao: int (dias)
     * - (legado, ignorado) registrar_movimentacao: bool
     * - (opcional) id_usuario: quando admin seleciona o vendedor
     * - (opcional) depositos_por_item: array de { id_carrinho_item, id_deposito|null }
     *
     * @param  StorePedidoRequest $request
     * @return JsonResponse
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function executar(StorePedidoRequest $request): JsonResponse
    {
        $usuarioId      = auth()->id();
        $idUsuarioInput = $request->input('id_usuario');
        $idUsuarioFinal = (int) $usuarioId;

        $query = Carrinho::with(['itens.variacao.produto'])
            ->where('id', $request->id_carrinho);
        $query->with([
            'itens.outlet.variacao',
            'itens.outlet.formasPagamento.formaPagamento',
            'itens.outletPagamento.formaPagamento',
        ]);

        if (!AuthHelper::podeVisualizarCarrinhosDeTodos()) {
            $query->where('id_usuario', $usuarioId);
        }

        $carrinho = $query->firstOrFail();
        if ($carrinho->itens->isEmpty()) {
            return response()->json(['message' => 'Carrinho está vazio.'], 422);
        }

        $conflitosOutlet = $this->conflitosOutlet($carrinho->itens);
        if ($conflitosOutlet !== []) {
            return response()->json([
                'message' => 'As condicoes comerciais de um ou mais itens outlet foram alteradas.',
                'code' => 'outlet_pricing_changed',
                'itens' => $conflitosOutlet,
            ], 409);
        }

        if ($idUsuarioInput !== null) {
            if (!AuthHelper::podeSelecionarVendedorPedido()) {
                throw ValidationException::withMessages([
                    'id_usuario' => ['Sem permissao para selecionar vendedor.'],
                ]);
            }

            $idUsuarioFinal = (int) $idUsuarioInput;
        }

        // 1) Mapa bruto vindo da UI
        $depositosMapBruto = collect($request->input('depositos_por_item', []))
            ->keyBy('id_carrinho_item')
            ->map(fn($r) => $r['id_deposito'] ?? null)
            ->all();

        // 2) Mapa RESOLVIDO usando o service (mapa > item.id_deposito > null)
        $depositosResolvidos = $this->resolverDepositosPorItem($carrinho->itens, $depositosMapBruto);

        $emConsignacao = $request->boolean('modo_consignacao');

        if ($emConsignacao) {
            $this->validarDepositosConsignacao($carrinho->itens, $depositosResolvidos);
        }

        $this->validarPrecosEditados($carrinho->itens);

        return DB::transaction(function () use ($request, $carrinho, $usuarioId, $idUsuarioFinal, $depositosResolvidos, $emConsignacao) {
            $total        = $this->calcularTotalItens($carrinho->itens);
            $dataPedido   = Carbon::now('America/Belem');
            $prazoPadrao  = (int) config('orders.prazo_padrao_dias_uteis', 60);
            $prazoUteis   = (int) ($request->input('prazo_dias_uteis') ?? $prazoPadrao);

            $prazoConsignacao = null;
            if ($emConsignacao) {
                $prazoConsignacao = (int) ($request->input('prazo_consignacao') ?? $prazoPadrao);
                $prazoUteis = $prazoConsignacao > 0 ? $prazoConsignacao : $prazoPadrao;
            }

            // Pedido + itens + status inicial
            $pedido = $this->pedidoFactory->criarPedido([
                'id_cliente'       => $request->id_cliente,
                'id_usuario'       => $idUsuarioFinal,
                'id_parceiro'      => $request->id_parceiro,
                'data_pedido'      => $dataPedido,
                'valor_total'      => $total,
                'observacoes'      => $request->observacoes,
                'prazo_dias_uteis' => $prazoUteis,
            ]);

            $this->auditLogger->logModel('created', $pedido, null, $pedido->fresh()->toArray(), $usuarioId);

            $itensPedido = $this->pedidoFactory->criarItens($pedido, $carrinho->itens);
            $this->pedidoFactory->registrarStatus($pedido, PedidoStatus::PEDIDO_CRIADO, $idUsuarioFinal);
            $this->registrarAuditoriaPrecoEditado($itensPedido, $usuarioId);

            // Consignação (registros + status)
            if ($emConsignacao) {
                $prazoDias  = $prazoConsignacao ?: $prazoPadrao;
                $prazoData  = Carbon::now('America/Belem')->addDays($prazoDias);

                // Usa o mapa resolvido para definir depósito das consignações
                $this->consignacaoFactory->criarLote(
                    $pedido,
                    $carrinho->itens,
                    $depositosResolvidos,
                    $prazoData,
                    $itensPedido
                );
                $this->pedidoFactory->registrarStatus($pedido, PedidoStatus::CONSIGNADO, $idUsuarioFinal);
            }

            $this->entregaProdutoService->criarDemandaPedido($pedido, $idUsuarioFinal, ! $emConsignacao);

            if ($emConsignacao) {
                $pedido->load('consignacoes');
                foreach ($pedido->consignacoes as $consignacao) {
                    $entrega = $this->entregaProdutoService->criarDemandaConsignacao($consignacao, $idUsuarioFinal);
                    $this->entregaProdutoService->reservarItem(
                        $entrega,
                        $consignacao->deposito_id,
                        null,
                        $idUsuarioFinal,
                        "Reserva inicial da consignacao #{$consignacao->id}",
                        "consignacao:{$consignacao->id}:reserva-inicial"
                    );
                }
            }

            $pedido->forceFill(['separacao_status' => 'pendente'])->save();

            // Data limite
            $this->prazoService->definirDataLimite($pedido, $prazoUteis);

            // Finaliza carrinho
            $carrinho->itens()->delete();
            $carrinho->update(['status' => 'finalizado']);

            $pedidoFresh = $pedido->fresh(['itens.variacao', 'statusAtual']);
            $this->auditLogger->logModel('finalized', $pedido, null, [
                'status' => $pedidoFresh?->statusAtual?->status,
                'separacao_status' => $pedidoFresh?->separacao_status,
                'modo_consignacao' => $emConsignacao,
                'registrar_movimentacao' => false,
                'itens' => $pedidoFresh?->itens?->toArray() ?? [],
            ], $usuarioId);

            return response()->json([
                'message' => 'Pedido criado com sucesso.',
                'pedido'  => $pedido->load('itens.variacao'),
                'is_consignacao' => $emConsignacao,
            ], 201);
        });
    }

    /**
     * Constrói um mapa resolvido id_carrinho_item => id_deposito,
     * usando o DepositoResolver para cada item.
     *
     * @param  Collection $itensCarrinho
     * @param  array      $depositosMapBruto  ['id_carrinho_item' => 'id_deposito' (ou null)]
     * @return array      ['id_carrinho_item' => ?int]
     */
    private function resolverDepositosPorItem(Collection $itensCarrinho, array $depositosMapBruto): array
    {
        $resolvido = [];
        foreach ($itensCarrinho as $item) {
            $resolvido[$item->id] = $this->resolver->resolverParaItem($item, $depositosMapBruto);
        }
        return $resolvido;
    }

    private function validarPrecosEditados(Collection $itensCarrinho): void
    {
        $erros = [];

        foreach ($itensCarrinho as $index => $item) {
            $precoOriginal = $this->resolverPrecoOriginal($item);
            $precoFinal = round((float) $item->preco_unitario, 2);

            if (abs($precoFinal - $precoOriginal) < 0.01) {
                continue;
            }

            if (!AuthHelper::podeEditarPrecoPedido()) {
                $erros["itens.{$index}.preco_unitario"] = 'Sem permissão para editar o preço do item na finalização.';
            }
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }
    }

    private function validarDepositosConsignacao(Collection $itensCarrinho, array $depositosResolvidos): void
    {
        $itensSemDeposito = $itensCarrinho
            ->filter(fn ($item) => empty($depositosResolvidos[$item->id] ?? $item->id_deposito))
            ->values();

        if ($itensSemDeposito->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'depositos_por_item' => ['Selecione o deposito de saida para todos os itens da consignacao.'],
        ]);
    }

    private function calcularTotalItens(Collection $itensCarrinho): float
    {
        return round($itensCarrinho->sum(function (CarrinhoItem $item) {
            return round((float) $item->preco_unitario, 2) * (int) $item->quantidade;
        }), 2);
    }

    private function registrarAuditoriaPrecoEditado(Collection $itensPedido, ?int $usuarioId): void
    {
        $itensPedido
            ->filter(fn (PedidoItem $item) => round((float) $item->preco_original, 2) !== round((float) $item->preco_unitario, 2))
            ->each(function (PedidoItem $item) use ($usuarioId) {
                $this->auditLogger->logModel('price_overridden', $item, [
                    'preco_original' => (float) $item->preco_original,
                ], [
                    'preco_original' => (float) $item->preco_original,
                    'preco_unitario' => (float) $item->preco_unitario,
                    'quantidade' => (int) $item->quantidade,
                    'subtotal' => (float) $item->subtotal,
                ], $usuarioId);
            });
    }

    private function resolverPrecoOriginal(CarrinhoItem $item): float
    {
        if ($item->outlet_id) {
            return round((float) $item->outlet_preco_final, 2);
        }

        $precoBase = round((float) ($item->variacao?->preco ?? 0), 2);
        $percentualOutlet = round((float) ($item->outlet?->formasPagamento?->max('percentual_desconto') ?? 0), 2);

        if ($item->outlet_id && $percentualOutlet > 0) {
            return round($precoBase * (1 - ($percentualOutlet / 100)), 2);
        }

        return $precoBase;
    }

    private function conflitosOutlet(Collection $itens): array
    {
        return $itens->filter(fn (CarrinhoItem $item) => $item->outlet_id || $item->outlet_preco_base !== null)
            ->map(function (CarrinhoItem $item) {
                $anterior = [
                    'outlet_id' => $item->outlet_id,
                    'outlet_pagamento_id' => $item->outlet_pagamento_id,
                    'preco_base' => $item->outlet_preco_base !== null ? (float) $item->outlet_preco_base : null,
                    'forma_pagamento_id' => $item->outlet_forma_pagamento_id,
                    'forma_pagamento' => $item->outlet_forma_pagamento,
                    'percentual_desconto' => $item->outlet_percentual_desconto !== null ? (float) $item->outlet_percentual_desconto : null,
                    'max_parcelas' => $item->outlet_max_parcelas,
                    'preco_final' => $item->outlet_preco_final !== null ? (float) $item->outlet_preco_final : null,
                ];

                $outlet = $item->outlet;
                $condicao = $item->outletPagamento;
                if (!$outlet || !$condicao || !$item->outlet_pagamento_id) {
                    return [
                        'id_carrinho_item' => (int) $item->id,
                        'snapshot_anterior' => $anterior,
                        'situacao_atual' => null,
                        'acao_necessaria' => 'selecionar_condicao',
                    ];
                }

                try {
                    $atualSnapshot = $this->outletPricing->buildSnapshot($outlet, $condicao);
                } catch (\DomainException) {
                    return [
                        'id_carrinho_item' => (int) $item->id,
                        'snapshot_anterior' => $anterior,
                        'situacao_atual' => null,
                        'acao_necessaria' => 'selecionar_condicao',
                    ];
                }

                $atual = [
                    'outlet_id' => $atualSnapshot['outlet_id'],
                    'outlet_pagamento_id' => $atualSnapshot['outlet_pagamento_id'],
                    'preco_base' => $atualSnapshot['outlet_preco_base'],
                    'forma_pagamento_id' => $atualSnapshot['outlet_forma_pagamento_id'],
                    'forma_pagamento' => $atualSnapshot['outlet_forma_pagamento'],
                    'percentual_desconto' => $atualSnapshot['outlet_percentual_desconto'],
                    'max_parcelas' => $atualSnapshot['outlet_max_parcelas'],
                    'preco_final' => $atualSnapshot['outlet_preco_final'],
                ];

                return $this->snapshotsIguais($anterior, $atual) ? null : [
                    'id_carrinho_item' => (int) $item->id,
                    'snapshot_anterior' => $anterior,
                    'situacao_atual' => $atual,
                    'acao_necessaria' => 'confirmar_reprecificacao',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function snapshotsIguais(array $anterior, array $atual): bool
    {
        foreach (['outlet_id', 'outlet_pagamento_id', 'forma_pagamento_id', 'forma_pagamento', 'max_parcelas'] as $campo) {
            if (($anterior[$campo] ?? null) != ($atual[$campo] ?? null)) {
                return false;
            }
        }
        foreach (['preco_base', 'percentual_desconto', 'preco_final'] as $campo) {
            if (abs((float) ($anterior[$campo] ?? -1) - (float) ($atual[$campo] ?? -2)) >= 0.01) {
                return false;
            }
        }
        return true;
    }
}
