<?php

namespace App\Http\Controllers;

use App\Enums\ContaStatus;
use App\Http\Requests\Financeiro\BaixaContaReceberRequest;
use App\Http\Requests\Financeiro\StoreContaReceberRequest;
use App\Http\Requests\Financeiro\UpdateContaReceberRequest;
use App\Http\Resources\ContaReceberResource;
use App\Models\ContaReceber;
use App\Services\ContaReceberCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ContaReceberController extends Controller
{
    public function __construct(
        private readonly ContaReceberCommandService $cmd,
    ) {}

    /**
     * Lista contas a receber com filtros e paginação.
     *
     * Filtros suportados:
     * - busca (descricao/numero_documento)
     * - status (ABERTA, PARCIAL, PAGA, CANCELADA)
     * - cliente (nome do cliente no pedido)
     * - numero_pedido (numero_externo do pedido)
     * - data_ini, data_fim (intervalo por data_vencimento)
     * - vencidas (bool) => vencimento passado e status != PAGA
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->has('vencidas')) {
            $request->merge([
                'vencidas' => filter_var($request->input('vencidas'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        $request->validate([
            'page'      => 'nullable|integer|min:1',
            'per_page'  => 'nullable|integer|min:1|max:200',
            'busca'     => 'nullable|string|max:255',
            'status'    => 'nullable|in:ABERTA,PARCIAL,PAGA,CANCELADA',
            'cliente'   => 'nullable|string|max:255',
            'numero_pedido' => 'nullable|string|max:80',
            'data_ini'  => 'nullable|date',
            'data_fim'  => 'nullable|date',
            'vencidas'  => 'nullable|boolean',
        ]);

        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 20);

        $q = ContaReceber::query()
            ->with(['pedido.cliente', 'pagamentos.usuario']);

        if ($request->filled('busca')) {
            $busca = '%' . $request->string('busca')->toString() . '%';
            $q->where(fn ($w) => $w
                ->where('descricao', 'like', $busca)
                ->orWhere('numero_documento', 'like', $busca)
            );
        }

        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }

        if ($request->filled('cliente')) {
            $cliente = '%' . $request->string('cliente')->toString() . '%';
            $q->whereHas('pedido.cliente', fn ($c) =>
            $c->where('nome', 'like', $cliente)
            );
        }

        if ($request->filled('numero_pedido')) {
            $np = '%' . $request->string('numero_pedido')->toString() . '%';
            $q->whereHas('pedido', fn ($p) =>
            $p->where('numero_externo', 'like', $np)
            );
        }

        if ($request->filled('data_ini')) {
            $q->whereDate('data_vencimento', '>=', $request->string('data_ini')->toString());
        }

        if ($request->filled('data_fim')) {
            $q->whereDate('data_vencimento', '<=', $request->string('data_fim')->toString());
        }

        if ($request->boolean('vencidas', false)) {
            $q->whereDate('data_vencimento', '<', now()->toDateString())
                ->where('status', '!=', ContaStatus::PAGA->value);
        }

        $q->orderBy('data_vencimento')->orderBy('id');

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return ContaReceberResource::collection($paginator)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Exibe uma conta a receber com relacionamentos.
     */
    public function show(ContaReceber $conta): JsonResponse
    {
        $conta->load(['pedido.cliente', 'pagamentos.usuario']);

        return response()->json([
            'data' => new ContaReceberResource($conta),
        ], Response::HTTP_OK);
    }

    /**
     * Cria uma conta a receber.
     */
    public function store(StoreContaReceberRequest $request): JsonResponse
    {
        $conta = $this->cmd->criar($request->validated());
        $conta->load(['pedido.cliente']);

        return response()->json([
            'data' => new ContaReceberResource($conta),
        ], Response::HTTP_CREATED);
    }

    /**
     * Atualiza uma conta a receber.
     */
    public function update(UpdateContaReceberRequest $request, ContaReceber $conta): JsonResponse
    {
        $conta = $this->cmd->atualizar($conta, $request->validated());
        $conta->load(['pedido.cliente', 'pagamentos.usuario']);

        return response()->json([
            'data' => new ContaReceberResource($conta),
        ], Response::HTTP_OK);
    }

    /**
     * Exclui (soft delete) uma conta a receber.
     */
    public function destroy(ContaReceber $conta): JsonResponse
    {
        $this->cmd->deletar($conta);

        return response()->json([
            'message' => 'Excluída com sucesso',
        ], Response::HTTP_OK);
    }

    /**
     * Registra um pagamento (baixa) em uma conta a receber.
     */
    public function pagar(BaixaContaReceberRequest $request, ContaReceber $conta): JsonResponse
    {
        $dados = $request->validated();

        if ($request->hasFile('comprovante')) {
            $dados['comprovante'] = $request->file('comprovante');
        }

        $pagamento = $this->cmd->registrarPagamento($conta, $dados);

        // retorna a conta atualizada (padrão similar ao contas a pagar)
        $conta->refresh()->load(['pedido.cliente', 'pagamentos.usuario']);

        return response()->json([
            'data' => new ContaReceberResource($conta),
        ], Response::HTTP_OK);
    }

    /**
     * Estorna (remove) um pagamento específico da conta a receber.
     * DELETE /contas-receber/{conta}/pagamentos/{pagamento}
     */
    public function estornarPagamento(ContaReceber $conta, int $pagamento): JsonResponse
    {
        $contaAtualizada = $this->cmd->estornarPagamento($conta, $pagamento);

        return response()->json([
            'data' => new ContaReceberResource($contaAtualizada),
        ], Response::HTTP_OK);
    }
}
