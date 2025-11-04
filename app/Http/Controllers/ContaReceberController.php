<?php

namespace App\Http\Controllers;

use App\Http\Requests\BaixaContaReceberRequest;
use App\Http\Requests\StoreContaReceberRequest;
use App\Http\Requests\UpdateContaReceberRequest;
use App\Http\Resources\ContaReceberResource;
use App\Models\ContaReceber;
use App\Services\ContaReceberService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ContaReceberController extends Controller
{
    protected ContaReceberService $service;

    public function __construct(ContaReceberService $service)
    {
        $this->service = $service;
    }

    /**
     * 🔎 Listagem com filtros e paginação
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 20);

        $query = ContaReceber::with(['pedido.cliente', 'pagamentos']);

        // ✅ Filtros avançados
        $query->when($request->filled('status'), fn($q) =>
        $q->where('status', $request->status)
        );

        $query->when($request->filled('forma_recebimento'), fn($q) =>
        $q->where('forma_recebimento', $request->forma_recebimento)
        );

        $query->when($request->filled('cliente'), fn($q) =>
        $q->whereHas('pedido.cliente', fn($c) =>
        $c->where('nome', 'like', "%{$request->cliente}%"))
        );

        $query->when($request->filled('numero_pedido'), fn($q) =>
        $q->whereHas('pedido', fn($p) =>
        $p->where('numero', 'like', "%{$request->numero_pedido}%"))
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
     * 📄 Exibe uma conta
     */
    public function show(int $id): JsonResponse
    {
        $conta = ContaReceber::with(['pedido.cliente', 'pagamentos'])->findOrFail($id);
        return response()->json(new ContaReceberResource($conta));
    }

    /**
     * ➕ Cria
     */
    public function store(StoreContaReceberRequest $request): JsonResponse
    {
        $conta = $this->service->criar($request->validated());
        return response()->json(new ContaReceberResource($conta), 201);
    }

    /**
     * ✏️ Atualiza
     */
    public function update(UpdateContaReceberRequest $request, int $id): JsonResponse
    {
        $conta = ContaReceber::findOrFail($id);
        $conta->update($request->validated());
        return response()->json(new ContaReceberResource($conta));
    }

    /**
     * ❌ Deleta
     */
    public function destroy(int $id): JsonResponse
    {
        ContaReceber::findOrFail($id)->delete();
        return response()->json(['message' => 'Conta removida com sucesso.']);
    }

    /**
     * 💰 Registra baixa de pagamento
     */
    public function baixa(BaixaContaReceberRequest $request, int $id): JsonResponse
    {
        $conta = ContaReceber::findOrFail($id);
        $this->service->registrarBaixa($conta, $request->validated());
        $conta->load('pagamentos');

        return response()->json(new ContaReceberResource($conta), 200);
    }
}
