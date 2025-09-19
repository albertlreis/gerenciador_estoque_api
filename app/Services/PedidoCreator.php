<?php

namespace App\Services;

use App\Enums\PedidoStatus;
use App\Helpers\AuthHelper;
use App\Http\Requests\StorePedidoRequest;
use App\Models\Carrinho;
use App\Models\Estoque;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\Consignacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class PedidoCreator
{
    public function __construct(
        private readonly PedidoPrazoService $pedidoPrazoService,
        private readonly EstoqueMovimentacaoService $movService,
        private readonly ReservaEstoqueService $reservaService,
    ) {}

    private function getDisponivel(int $variacaoId, ?int $depositoId): int
    {
        $saldo = Estoque::query()
            ->where('id_variacao', $variacaoId)
            ->when($depositoId, fn($q) => $q->where('id_deposito', $depositoId))
            ->sum('quantidade');

        $reservado = $this->reservaService->reservasEmAbertoPorDeposito($variacaoId, $depositoId);

        return (int)$saldo - (int)$reservado;
    }

    public function criar(StorePedidoRequest $request): JsonResponse
    {
        $usuarioId      = auth()->id();
        $idUsuarioFinal = $request->input('id_usuario');

        $query = Carrinho::with(['itens.variacao.produto'])
            ->where('id', $request->id_carrinho);

        if (!AuthHelper::hasPermissao('carrinhos.visualizar.todos')) {
            $query->where('id_usuario', $usuarioId);
        }

        $carrinho = $query->firstOrFail();

        if ($carrinho->itens->isEmpty()) {
            return response()->json(['message' => 'Carrinho está vazio.'], 422);
        }

        $idUsuarioFinal = $idUsuarioFinal ?: $carrinho->id_usuario;

        // Mapa de depósitos por item
        $depositosMap = collect($request->input('depositos_por_item', []))
            ->keyBy('id_carrinho_item')
            ->map(fn($r) => $r['id_deposito'] ?? null);

        $registrarMov = $request->boolean('registrar_movimentacao');
        $emConsignacao = $request->boolean('modo_consignacao');

        // === (1) Validação de estoque por depósito quando for movimentar (e quando NÃO for consignação) ===
        if ($registrarMov && !$emConsignacao) {
            $erros = [];
            foreach ($carrinho->itens as $item) {
                $depId = $depositosMap->get($item->id) ?? $item->id_deposito ?? null;
                if (!$depId) {
                    $erros[] = "Selecione o depósito para o item {$item->id} ({$item->nome_completo}).";
                    continue;
                }
                $disp = $this->getDisponivel($item->id_variacao, (int)$depId);
                if ($disp < (int)$item->quantidade) {
                    $erros[] = "Estoque insuficiente no depósito selecionado para o item {$item->id} ({$item->nome_completo}). Disponível: {$disp}, solicitado: {$item->quantidade}.";
                }
            }
            if (!empty($erros)) {
                throw ValidationException::withMessages(['estoque' => $erros]);
            }
        }

        // === (2) Criação do pedido + (3) status + (4) consignações ===
        return DB::transaction(function () use ($request, $carrinho, $idUsuarioFinal, $depositosMap, $registrarMov, $emConsignacao) {
            $total = $carrinho->itens->sum('subtotal');

            $dataPedido      = Carbon::now('America/Belem');
            $prazoPadrao     = (int) config('orders.prazo_padrao_dias_uteis', 60);
            $prazoDiasUteis  = (int) ($request->input('prazo_dias_uteis') ?? $prazoPadrao);

            $pedido = Pedido::create([
                'id_cliente'          => $request->id_cliente,
                'id_usuario'          => $idUsuarioFinal,
                'id_parceiro'         => $request->id_parceiro,
                'data_pedido'         => $dataPedido,
                'valor_total'         => $total,
                'observacoes'         => $request->observacoes,
                'prazo_dias_uteis'    => $prazoDiasUteis,
            ]);

            foreach ($carrinho->itens as $item) {
                PedidoItem::create([
                    'id_pedido'      => $pedido->id,
                    'id_variacao'    => $item->id_variacao,
                    'quantidade'     => $item->quantidade,
                    'preco_unitario' => $item->preco_unitario,
                    'subtotal'       => $item->subtotal,
                ]);
            }

            PedidoStatusHistorico::create([
                'pedido_id'   => $pedido->id,
                'status'      => PedidoStatus::PEDIDO_CRIADO,
                'data_status' => Carbon::now('America/Belem'),
                'usuario_id'  => $idUsuarioFinal,
            ]);

            // Consignação → cria registros (como você já tinha)
            if ($emConsignacao) {
                $prazoDias   = (int) $request->input('prazo_consignacao');
                $prazoData   = Carbon::now('America/Belem')->addDays($prazoDias);

                foreach ($carrinho->itens as $item) {
                    $depositoIdEscolhido = $depositosMap->get($item->id) ?? $item->id_deposito ?? null;

                    Consignacao::create([
                        'pedido_id'           => $pedido->id,
                        'produto_variacao_id' => $item->id_variacao,
                        'deposito_id'         => $depositoIdEscolhido,
                        'quantidade'          => $item->quantidade,
                        'data_envio'          => Carbon::now('America/Belem'),
                        'prazo_resposta'      => $prazoData->copy()->startOfDay(),
                        'status'              => 'pendente',
                    ]);
                }

                PedidoStatusHistorico::create([
                    'pedido_id'   => $pedido->id,
                    'status'      => PedidoStatus::CONSIGNADO,
                    'data_status' => Carbon::now('America/Belem'),
                    'usuario_id'  => $idUsuarioFinal,
                ]);
            }

            // === (5) Movimentação OU Reserva ===
            if (!$emConsignacao) {
                foreach ($carrinho->itens as $item) {
                    $depId = $depositosMap->get($item->id) ?? $item->id_deposito ?? null;

                    if ($registrarMov) {
                        // Validação final (defensivo, caso chegue sem depósito)
                        if (!$depId) {
                            throw ValidationException::withMessages([
                                'depositos_por_item' => ["Selecione o depósito do item {$item->id} para registrar a movimentação."]
                            ]);
                        }
                        // Saída para cliente
                        $this->movService->registrarSaidaEntregaCliente(
                            variacaoId: $item->id_variacao,
                            depositoSaidaId: (int)$depId,
                            quantidade: (int)$item->quantidade,
                            usuarioId: $idUsuarioFinal,
                            observacao: "Pedido #{$pedido->id}"
                        );
                    } else {
                        // Sem movimentação → reservar (se houver depósito)
                        if ($depId) {
                            $this->reservaService->reservar(
                                variacaoId: $item->id_variacao,
                                depositoId: (int)$depId,
                                quantidade: (int)$item->quantidade,
                                pedidoId: $pedido->id,
                                motivo: 'pedido_sem_movimentacao'
                            );
                        }
                    }
                }
            }

            // (6) Calcula data limite
            $this->pedidoPrazoService->definirDataLimite($pedido);

            // (7) Limpa e finaliza carrinho
            $carrinho->itens()->delete();
            $carrinho->update(['status' => 'finalizado']);

            return response()->json([
                'message' => 'Pedido criado com sucesso.',
                'pedido'  => $pedido->load('itens.variacao'),
            ], 201);
        });
    }
}
