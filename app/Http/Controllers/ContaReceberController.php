<?php

namespace App\Http\Controllers;

use App\Http\Requests\BaixaContaReceberRequest;
use App\Http\Requests\EstornarContaReceberRequest;
use App\Http\Requests\StoreContaReceberRequest;
use App\Http\Requests\UpdateContaReceberRequest;
use App\Http\Resources\ContaReceberResource;
use App\Models\ContaReceber;
use App\Services\ContaReceberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ContaReceberController extends Controller
{
    protected ContaReceberService $service;

    public function __construct(ContaReceberService $service)
    {
        $this->service = $service;
    }

    /**
     * Lista contas a receber com filtros e paginação.
     *
     * Filtros suportados:
     * - status
     * - forma_recebimento
     * - cliente (nome do cliente do pedido)
     * - numero_pedido (campo "numero" do pedido, se existir)
     * - data_inicio, data_fim (intervalo por data_vencimento)
     * - valor_min, valor_max (intervalo por valor_liquido)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int)$request->input('per_page', 20);

        $query = ContaReceber::with(['pedido.cliente', 'pagamentos']);

        $query->when($request->filled('status'), fn($q) =>
        $q->where('status', $request->status)
        );

        $query->when($request->filled('forma_recebimento'), fn($q) =>
        $q->where('forma_recebimento', $request->forma_recebimento)
        );

        $query->when($request->filled('cliente'), fn($q) =>
        $q->whereHas('pedido.cliente', fn($c) =>
        $c->where('nome', 'like', "%{$request->cliente}%")
        )
        );

        // OBS: seu model Pedido exibido não tem 'numero'. Mantive como estava,
        // mas se o correto for 'numero_externo', troque aqui.
        $query->when($request->filled('numero_pedido'), fn($q) =>
        $q->whereHas('pedido', fn($p) =>
        $p->where('numero_externo', 'like', "%{$request->numero_pedido}%")
        )
        );

        if ($request->filled('data_inicio') && $request->filled('data_fim')) {
            $query->whereBetween('data_vencimento', [$request->data_inicio, $request->data_fim]);
        }

        if ($request->filled('valor_min') && $request->filled('valor_max')) {
            $query->whereBetween('valor_liquido', [$request->valor_min, $request->valor_max]);
        }

        $contas = $query->orderByDesc('data_vencimento')->paginate($perPage);

        return ContaReceberResource::collection($contas)
            ->additional(['meta' => ['filtros_aplicados' => $request->all()]])
            ->response();
    }

    /**
     * Exibe o detalhe de uma conta a receber.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $conta = ContaReceber::with(['pedido.cliente', 'pagamentos'])->findOrFail($id);
        return response()->json(new ContaReceberResource($conta), Response::HTTP_OK);
    }

    /**
     * Cria uma conta a receber.
     *
     * @param StoreContaReceberRequest $request
     * @return JsonResponse
     */
    public function store(StoreContaReceberRequest $request): JsonResponse
    {
        $conta = $this->service->criar($request->validated());
        return response()->json(new ContaReceberResource($conta), Response::HTTP_CREATED);
    }

    /**
     * Atualiza uma conta a receber.
     * Recalcula automaticamente: valor_liquido (quando aplicável), valor_recebido, saldo_aberto e status.
     *
     * @param UpdateContaReceberRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateContaReceberRequest $request, int $id): JsonResponse
    {
        $conta = ContaReceber::with('pagamentos')->findOrFail($id);

        $conta = $this->service->atualizar($conta, $request->validated());

        return response()->json(new ContaReceberResource($conta), Response::HTTP_OK);
    }

    /**
     * Remove (soft delete) uma conta a receber.
     * Se houver pagamentos registrados, realiza estorno automático (pagamento negativo) antes do soft delete.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $conta = ContaReceber::with('pagamentos')->findOrFail($id);

        $this->service->remover($conta, 'Remoção via endpoint');

        return response()->json([
            'message' => 'Conta removida com sucesso (soft delete) com estorno automático, se aplicável.',
        ], Response::HTTP_OK);
    }

    /**
     * Registra baixa (pagamento) em uma conta a receber.
     *
     * @param BaixaContaReceberRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function baixa(BaixaContaReceberRequest $request, int $id): JsonResponse
    {
        $conta = ContaReceber::findOrFail($id);

        $this->service->registrarBaixa($conta, $request->validated());

        $conta->load('pagamentos', 'pedido.cliente');

        return response()->json(new ContaReceberResource($conta), Response::HTTP_OK);
    }

    /**
     * Estorna uma conta a receber (não remove).
     * Cria um pagamento negativo para anular o valor recebido e marca status como ESTORNADO.
     *
     * @param EstornarContaReceberRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function estornar(EstornarContaReceberRequest $request, int $id): JsonResponse
    {
        $conta = ContaReceber::with('pagamentos')->findOrFail($id);

        $this->service->estornar($conta, $request->validated()['motivo'] ?? null);

        $conta->load('pagamentos', 'pedido.cliente');

        return response()->json(new ContaReceberResource($conta), Response::HTTP_OK);
    }
}
