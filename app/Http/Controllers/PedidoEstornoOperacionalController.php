<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Pedido;
use App\Services\PedidoEstornoOperacionalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PedidoEstornoOperacionalController extends Controller
{
    public function __construct(private readonly PedidoEstornoOperacionalService $service) {}

    public function index(Pedido $pedido): JsonResponse
    {
        $this->autorizar();

        return response()->json(['data' => $this->service->preview($pedido)]);
    }

    public function store(Request $request, Pedido $pedido): JsonResponse
    {
        $this->autorizar();

        $data = $request->validate([
            'produto_entrega_item_id' => ['required', 'integer'],
            'tipo' => ['required', Rule::in([
                PedidoEstornoOperacionalService::TIPO_RECEBIMENTO,
                PedidoEstornoOperacionalService::TIPO_ENTREGA,
            ])],
            'modo' => [
                Rule::requiredIf($request->input('tipo') === PedidoEstornoOperacionalService::TIPO_ENTREGA),
                'nullable',
                Rule::in([
                    PedidoEstornoOperacionalService::MODO_MANTER_EM_ENTREGA,
                    PedidoEstornoOperacionalService::MODO_DEVOLVER_ESTOQUE,
                ]),
            ],
            'quantidade' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'min:3', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        $resultado = $this->service->executar($pedido, $data, (int) auth()->id());

        return response()->json([
            'message' => $resultado['repetido']
                ? 'Estorno operacional já processado.'
                : 'Estorno operacional realizado com sucesso.',
            'data' => $resultado,
        ]);
    }

    private function autorizar(): void
    {
        abort_unless(
            AuthHelper::hasPermissao('estoque.movimentar'),
            403,
            'Sem permissão para estornar operações de estoque.'
        );
    }
}
