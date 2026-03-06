<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Resources\PedidoSeparacaoResource;
use App\Models\Pedido;
use App\Services\AuditLogger;
use App\Services\EstoqueMovimentacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EstoqueSeparacaoController extends Controller
{
    public function __construct(
        private readonly EstoqueMovimentacaoService $movimentacaoService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (!AuthHelper::podeGerenciarSeparacaoPedido()) {
            return response()->json(['message' => 'Sem permissão para visualizar a fila de separação.'], 403);
        }

        $status = (string) $request->input('status', 'pendente');
        $statusPermitidos = ['pendente', 'separado', 'entregue'];
        if (!in_array($status, $statusPermitidos, true)) {
            $status = 'pendente';
        }

        $pedidos = Pedido::query()
            ->with([
                'cliente:id,nome,telefone',
                'usuario:id,nome',
                'parceiro:id,nome',
                'statusAtual',
                'itens.variacao.produto',
                'itens.variacao.atributos',
                'separadoPor:id,nome',
                'entreguePor:id,nome',
            ])
            ->where('separacao_status', $status)
            ->orderByDesc('data_pedido')
            ->get();

        return response()->json([
            'data' => PedidoSeparacaoResource::collection($pedidos),
        ]);
    }

    public function marcarSeparado(Pedido $pedido): JsonResponse
    {
        if (!AuthHelper::podeGerenciarSeparacaoPedido()) {
            return response()->json(['message' => 'Sem permissão para alterar a separação.'], 403);
        }

        if ($pedido->separacao_status === 'entregue') {
            return response()->json(['message' => 'Pedido já foi entregue.'], 422);
        }

        $antes = ['separacao_status' => $pedido->separacao_status];

        $pedido->forceFill([
            'separacao_status' => 'separado',
            'separado_em' => now(),
            'separado_por' => auth()->id(),
        ])->save();

        $this->auditLogger->logModel('separation_marked', $pedido, $antes, [
            'separacao_status' => $pedido->separacao_status,
            'separado_em' => optional($pedido->separado_em)->toIso8601String(),
            'separado_por' => $pedido->separado_por,
        ], auth()->id());

        return response()->json([
            'message' => 'Pedido marcado como separado.',
            'data' => new PedidoSeparacaoResource($pedido->fresh([
                'cliente:id,nome,telefone',
                'usuario:id,nome',
                'parceiro:id,nome',
                'statusAtual',
                'itens.variacao.produto',
                'itens.variacao.atributos',
                'separadoPor:id,nome',
                'entreguePor:id,nome',
            ])),
        ]);
    }

    public function marcarEntregue(Pedido $pedido): JsonResponse
    {
        if (!AuthHelper::podeGerenciarSeparacaoPedido()) {
            return response()->json(['message' => 'Sem permissão para alterar a separação.'], 403);
        }

        if ($pedido->separacao_status === 'entregue') {
            return response()->json(['message' => 'Pedido já foi entregue.'], 422);
        }

        DB::transaction(function () use ($pedido) {
            foreach ($pedido->itens as $item) {
                $this->movimentacaoService->registrarSaidaPedido(
                    variacaoId: (int) $item->id_variacao,
                    depositoSaidaId: (int) $item->id_deposito,
                    quantidade: (int) $item->quantidade,
                    usuarioId: (int) auth()->id(),
                    observacao: "Entrega do pedido #{$pedido->id} pela fila de separação",
                    pedidoId: (int) $pedido->id,
                    pedidoItemId: (int) $item->id,
                );
            }

            $antes = ['separacao_status' => $pedido->separacao_status];

            $pedido->forceFill([
                'separacao_status' => 'entregue',
                'entregue_em' => now(),
                'entregue_por' => auth()->id(),
            ])->save();

            $this->auditLogger->logModel('delivery_marked', $pedido, $antes, [
                'separacao_status' => $pedido->separacao_status,
                'entregue_em' => optional($pedido->entregue_em)->toIso8601String(),
                'entregue_por' => $pedido->entregue_por,
            ], auth()->id());
        });

        return response()->json([
            'message' => 'Pedido marcado como entregue.',
            'data' => new PedidoSeparacaoResource($pedido->fresh([
                'cliente:id,nome,telefone',
                'usuario:id,nome',
                'parceiro:id,nome',
                'statusAtual',
                'itens.variacao.produto',
                'itens.variacao.atributos',
                'separadoPor:id,nome',
                'entreguePor:id,nome',
            ])),
        ]);
    }
}
