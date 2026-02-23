<?php

namespace App\Services;

use App\Enums\PedidoStatus;
use App\Helpers\AuthHelper;
use App\Http\Requests\StorePedidoRequest;
use App\Models\Carrinho;
use App\Services\Movimentacao\MovimentarEstoqueStrategy;
use App\Services\Movimentacao\ReservarEstoqueStrategy;
use App\Support\Audit\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Caso de uso de finalização de pedido.
 *
 * Orquestra:
 * - Validação de depósitos/estoque (quando registrar movimentação);
 * - Criação do Pedido + Itens + Status;
 * - Criação de registros de Consignação (quando aplicável);
 * - Movimentação OU Reserva de estoque (ambos os modos: normal e consignado);
 * - Definição de data limite;
 * - Finalização do carrinho.
 */
final class FinalizarPedidoService
{
    /**
     * @param PedidoFactory                $pedidoFactory         Criação de pedido/itens/status.
     * @param ConsignacaoFactory           $consignacaoFactory    Criação de consignações por item.
     * @param PedidoPrazoService           $prazoService          Cálculo/definição de data limite.
     * @param PedidoFinalizacaoValidator   $validator             Regras de validação antes de movimentar.
     * @param DepositoResolver             $resolver              Resolve depósito por item (mapa > item).
     * @param MovimentarEstoqueStrategy    $movimentarStrategy    Strategy para registrar saídas (estoque).
     * @param ReservarEstoqueStrategy      $reservarStrategy      Strategy para criar reservas.
     */
    public function __construct(
        private readonly PedidoFactory $pedidoFactory,
        private readonly ConsignacaoFactory $consignacaoFactory,
        private readonly PedidoPrazoService $prazoService,
        private readonly PedidoFinalizacaoValidator $validator,
        private readonly DepositoResolver $resolver,
        private readonly MovimentarEstoqueStrategy $movimentarStrategy,
        private readonly ReservarEstoqueStrategy $reservarStrategy,
        private readonly ContaReceberService $contaReceberService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Executa a finalização do pedido a partir de um carrinho existente.
     *
     * Espera-se que a StorePedidoRequest (já validada) contenha:
     * - id_carrinho, id_cliente, (opcional) id_parceiro, observacoes
     * - (opcional) modo_consignacao: bool
     * - (se modo_consignacao) prazo_consignacao: int (dias)
     * - (opcional) registrar_movimentacao: bool
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

        if (!AuthHelper::podeVisualizarCarrinhosDeTodos()) {
            $query->where('id_usuario', $usuarioId);
        }

        $carrinho = $query->firstOrFail();
        if ($carrinho->itens->isEmpty()) {
            return response()->json(['message' => 'Carrinho está vazio.'], 422);
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

        $registrarMov  = $request->boolean('registrar_movimentacao');
        $emConsignacao = $request->boolean('modo_consignacao');

        // Validação quando for movimentar (aplica a normal e consignado)
        if ($registrarMov) {
            $this->validator->validarAntesDeMovimentar($carrinho->itens, $depositosResolvidos);
        }

        return DB::transaction(function () use ($request, $carrinho, $idUsuarioFinal, $depositosResolvidos, $registrarMov, $emConsignacao) {
            $total        = $carrinho->itens->sum('subtotal');
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

            $this->pedidoFactory->criarItens($pedido, $carrinho->itens);
            $this->pedidoFactory->registrarStatus($pedido, PedidoStatus::PEDIDO_CRIADO, $idUsuarioFinal);

            // Consignação (registros + status)
            if ($emConsignacao) {
                $prazoDias  = $prazoConsignacao ?: $prazoPadrao;
                $prazoData  = Carbon::now('America/Belem')->addDays($prazoDias);

                // Usa o mapa resolvido para definir depósito das consignações
                $this->consignacaoFactory->criarLote($pedido, $carrinho->itens, $depositosResolvidos, $prazoData);
                $this->pedidoFactory->registrarStatus($pedido, PedidoStatus::CONSIGNADO, $idUsuarioFinal);
            }

            // Movimentação OU Reserva (ambos usam o mapa resolvido)
            if ($registrarMov) {
                $this->movimentarStrategy->processar($pedido, $carrinho->itens, $depositosResolvidos, $idUsuarioFinal);
            } else {
                $this->reservarStrategy->processar($pedido, $carrinho->itens, $depositosResolvidos, $idUsuarioFinal);
            }

            // Data limite
            $this->prazoService->definirDataLimite($pedido, $prazoUteis);

            // Cria conta a receber (apenas se não for consignado)
            if (!$emConsignacao) {
                try {
                    $this->contaReceberService->gerarPorPedido($pedido);
                } catch (Throwable $e) {
                    report($e);
                    throw new RuntimeException("Falha ao gerar conta a receber: {$e->getMessage()}");
                }
            }

            // Finaliza carrinho
            $carrinho->itens()->delete();
            $carrinho->update(['status' => 'finalizado']);

            $this->auditLogger->logCreate(
                $pedido,
                'pedidos',
                "Pedido criado: #{$pedido->id}",
                [
                    'numero_externo' => $pedido->numero_externo,
                    'modo_consignacao' => $emConsignacao,
                    'registrar_movimentacao' => $registrarMov,
                    'itens' => $pedido->itens()->count(),
                    'valor_total' => $pedido->valor_total,
                ]
            );

            return response()->json([
                'message' => 'Pedido criado com sucesso.',
                'pedido'  => $pedido->load('itens.variacao'),
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
}
