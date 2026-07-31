<?php

namespace App\Http\Controllers;

use App\Enums\PedidoStatus;
use App\Helpers\AuthHelper;
use App\Models\Pedido;
use App\Models\PedidoReconciliacao;
use App\Services\AuditoriaEventoService;
use App\Services\PedidoReconciliacaoPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PedidoReconciliacaoController extends Controller
{
    public function __construct(private readonly PedidoReconciliacaoPreviewService $preview, private readonly AuditoriaEventoService $auditoria) {}

    public function aprovar(Request $request, Pedido $pedido): JsonResponse
    {
        abort_unless(AuthHelper::hasPermissao('pedidos.editar'), 403);
        $dados = $request->validate([
            'fonte_verdade' => ['required', 'in:entrega_cliente'],
            'justificativa' => ['required', 'string', 'min:10'],
            'evidencia' => ['required', 'string', 'min:10'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'confirmacao_documental' => ['accepted'],
            'confirmacao_fisica' => ['accepted'],
        ]);

        $snapshot = $this->preview->preview($pedido);
        abort_unless($snapshot['divergencia'] ?? false, 422, 'Pedido nao possui divergencia operacional para reconciliar.');

        $reconciliacao = PedidoReconciliacao::firstOrCreate(
            ['idempotency_key' => $dados['idempotency_key']],
            [
                'pedido_id' => $pedido->id,
                'usuario_id' => auth()->id(),
                'fonte_verdade' => $dados['fonte_verdade'],
                'justificativa' => $dados['justificativa'],
                'evidencia' => $dados['evidencia'],
                'snapshot_json' => $snapshot,
            ]
        );

        if ($reconciliacao->pedido_id !== $pedido->id) {
            return response()->json(['message' => 'Chave de idempotencia ja usada em outro pedido.'], 422);
        }

        return response()->json(['data' => $reconciliacao, 'message' => 'Reconciliação aprovada; aguarda aplicação controlada.'], 201);
    }

    public function aplicar(Request $request, Pedido $pedido, PedidoReconciliacao $reconciliacao): JsonResponse
    {
        abort_unless(AuthHelper::hasPermissao('pedidos.editar'), 403);
        abort_unless($reconciliacao->pedido_id === $pedido->id, 404);
        $request->validate(['confirmar_aplicacao' => ['accepted']]);

        if ($reconciliacao->aplicada_em) return response()->json(['data' => $reconciliacao]);

        $reconciliacao = DB::transaction(function () use ($pedido, $reconciliacao) {
            $reconciliacao = PedidoReconciliacao::query()->lockForUpdate()->findOrFail($reconciliacao->id);
            if ($reconciliacao->aplicada_em) return $reconciliacao;

            $pedido->historicoStatus()->create([
                'status' => PedidoStatus::ENTREGA_CLIENTE,
                'data_status' => now(),
                'usuario_id' => auth()->id(),
                'observacoes' => "Reconciliação controlada #{$reconciliacao->id}: entrega ao cliente confirmada.",
            ]);
            $reconciliacao->update(['status' => 'aplicada', 'aplicada_em' => now()]);
            return $reconciliacao->fresh();
        });

        $this->auditoria->registrar('pedidos', 'reconciliacao_aplicada', "Reconciliação aplicada ao pedido #{$pedido->id}.", $pedido, [], ['reconciliacao_id' => $reconciliacao->id, 'fonte_verdade' => 'entrega_cliente']);
        return response()->json(['data' => $reconciliacao]);
    }
}
