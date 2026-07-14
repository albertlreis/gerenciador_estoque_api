<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Resources\ProdutoEntregaItemResource;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Services\EntregaProdutoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PedidoAntecipacaoController extends Controller
{
    public function __construct(private readonly EntregaProdutoService $entregas) {}

    public function store(Request $request, Pedido $pedido, PedidoItem $item): JsonResponse
    {
        $this->autorizar();
        $data = $request->validate([
            'deposito_id' => ['required', 'integer', 'exists:depositos,id'],
            'quantidade' => ['required', 'integer', 'min:1'],
            'observacao' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        $entrega = $this->entregas->registrarAntecipacao(
            $pedido,
            $item,
            (int) $data['deposito_id'],
            (int) $data['quantidade'],
            trim((string) $data['idempotency_key']),
            auth()->id() ? (int) auth()->id() : null,
            $data['observacao'] ?? null
        );

        return response()->json([
            'message' => 'Atendimento antecipado registrado com sucesso.',
            'data' => (new ProdutoEntregaItemResource($entrega))->resolve($request),
        ]);
    }

    public function cancelar(Request $request, Pedido $pedido, PedidoItem $item): JsonResponse
    {
        $this->autorizar();
        $data = $request->validate([
            'observacao' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        $entrega = $this->entregas->cancelarAntecipacao(
            $pedido,
            $item,
            trim((string) $data['idempotency_key']),
            auth()->id() ? (int) auth()->id() : null,
            $data['observacao'] ?? null
        );

        return response()->json([
            'message' => 'Reservas antecipadas nao consumidas canceladas com sucesso.',
            'data' => (new ProdutoEntregaItemResource($entrega))->resolve($request),
        ]);
    }

    private function autorizar(): void
    {
        abort_unless(config('pedidos.fluxo_operacional_v2_enabled'), 404);
        abort_unless(AuthHelper::hasPermissao('estoque.movimentar'), 403, 'Sem permissao para antecipar atendimento com estoque atual.');
    }
}
