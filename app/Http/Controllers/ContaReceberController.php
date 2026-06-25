<?php

namespace App\Http\Controllers;

use App\Enums\ContaStatus;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Services\ContaAzulCobrancaService;
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
        private readonly ContaAzulCobrancaService $contaAzulCobrancas,
    ) {}

    /**
     * Lista contas a receber com filtros e paginação.
     *
     * Filtros suportados:
     * - busca (descricao/numero_documento)
     * - status (ABERTA, PARCIAL, PAGA, CANCELADA)
     * - cliente (nome do cliente direto ou do cliente no pedido)
     * - numero_pedido (numero_externo do pedido)
     * - data_ini, data_fim (intervalo por data_vencimento)
     * - vencidas (bool) => vencimento passado e status != PAGA
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        foreach (['vencidas', 'em_aberto'] as $booleanFilter) {
            if ($request->has($booleanFilter)) {
                $request->merge([
                    $booleanFilter => filter_var($request->input($booleanFilter), FILTER_VALIDATE_BOOLEAN),
                ]);
            }
        }

        $request->validate([
            'page'      => 'nullable|integer|min:1',
            'per_page'  => 'nullable|integer|min:1|max:200',
            'busca'     => 'nullable|string|max:255',
            'status'    => 'nullable|in:ABERTA,PARCIAL,PAGA,CANCELADA',
            'cliente'   => 'nullable|string|max:255',
            'cliente_id' => 'nullable|integer|exists:clientes,id',
            'numero_pedido' => 'nullable|string|max:80',
            'forma_recebimento' => 'nullable|string|max:50',
            'centro_custo_id' => 'nullable|integer|exists:centros_custo,id',
            'categoria_id' => 'nullable|integer|exists:categorias_financeiras,id',
            'conta_financeira_id' => 'nullable|integer|exists:contas_financeiras,id',
            'data_ini'  => 'nullable|date',
            'data_fim'  => 'nullable|date',
            'vencidas'  => 'nullable|boolean',
            'em_aberto' => 'nullable|boolean',
            'origem' => 'nullable|in:recorrente',
        ]);

        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 20);

        $q = ContaReceber::query()
            ->with(['cliente', 'pedido.cliente', 'parcelamento', 'recorrencia', 'pagamentos.usuario', 'pagamentos.contaFinanceira', 'cobrancaContaAzul']);

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
            $q->where(fn ($w) => $w
                ->whereHas('cliente', fn ($c) => $c->where('nome', 'like', $cliente))
                ->orWhereHas('pedido.cliente', fn ($c) => $c->where('nome', 'like', $cliente))
            );
        }

        if ($request->filled('cliente_id')) {
            $clienteId = $request->integer('cliente_id');
            $q->where(fn ($w) => $w
                ->where('cliente_id', $clienteId)
                ->orWhereHas('pedido', fn ($p) => $p->where('id_cliente', $clienteId))
            );
        }

        if ($request->filled('numero_pedido')) {
            $np = '%' . $request->string('numero_pedido')->toString() . '%';
            $q->whereHas('pedido', fn ($p) =>
            $p->where('numero_externo', 'like', $np)
            );
        }

        if ($request->filled('forma_recebimento')) {
            $q->where('forma_recebimento', $request->string('forma_recebimento')->toString());
        }

        if ($request->filled('centro_custo_id')) {
            $q->where('centro_custo_id', $request->integer('centro_custo_id'));
        }

        if ($request->filled('categoria_id')) {
            $q->where('categoria_id', $request->integer('categoria_id'));
        }

        if ($request->filled('conta_financeira_id')) {
            $contaFinanceiraId = $request->integer('conta_financeira_id');
            $q->whereHas('pagamentos', fn ($pagamento) => $pagamento->where('conta_financeira_id', $contaFinanceiraId));
        }

        if ($request->string('origem')->toString() === 'recorrente') {
            $q->whereNotNull('despesa_recorrente_id');
        }

        if ($request->filled('data_ini')) {
            $q->whereDate('data_vencimento', '>=', $request->string('data_ini')->toString());
        }

        if ($request->filled('data_fim')) {
            $q->whereDate('data_vencimento', '<=', $request->string('data_fim')->toString());
        }

        if ($request->boolean('vencidas', false)) {
            $q->whereDate('data_vencimento', '<', now()->toDateString())
                ->whereNotIn('status', [ContaStatus::PAGA->value, ContaStatus::CANCELADA->value]);
        }

        if ($request->boolean('em_aberto', false)) {
            $q->whereNotIn('status', [ContaStatus::PAGA->value, ContaStatus::CANCELADA->value]);
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
        $conta->load(['cliente', 'pedido.cliente', 'categoria', 'centroCusto', 'parcelamento', 'recorrencia', 'pagamentos.usuario', 'pagamentos.contaFinanceira', 'cobrancaContaAzul']);

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
        $conta->load(['cliente', 'pedido.cliente', 'recorrencia']);

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
        $conta->load(['cliente', 'pedido.cliente', 'recorrencia', 'pagamentos.usuario']);

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
        $conta->refresh()->load(['cliente', 'pedido.cliente', 'recorrencia', 'pagamentos.usuario', 'pagamentos.contaFinanceira']);

        return response()->json([
            'data' => new ContaReceberResource($conta),
        ], Response::HTTP_OK);
    }

    public function gerarBoletoContaAzul(ContaReceber $conta): JsonResponse
    {
        try {
            $this->contaAzulCobrancas->gerarBoleto($conta);
        } catch (ContaAzulException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $conta->refresh()->load(['cliente', 'pedido.cliente', 'categoria', 'centroCusto', 'parcelamento', 'recorrencia', 'pagamentos.usuario', 'pagamentos.contaFinanceira', 'cobrancaContaAzul']);

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
        $contaAtualizada->load(['cliente', 'pedido.cliente', 'recorrencia', 'pagamentos.usuario', 'pagamentos.contaFinanceira']);

        return response()->json([
            'data' => new ContaReceberResource($contaAtualizada),
        ], Response::HTTP_OK);
    }
}
