<?php

namespace App\Http\Controllers\Integrations;

use App\Helpers\AuthHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\ContaAzulManualTokenRequest;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Models\ContaAzulImportBatch;
use App\Integrations\ContaAzul\Models\ContaAzulSyncLog;
use App\Integrations\ContaAzul\Services\ConciliacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ContaAzulLocalCreationService;
use App\Integrations\ContaAzul\Services\ImportacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ReconciliacaoContaAzulService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContaAzulIntegracaoController extends Controller
{
    public function __construct(
        private readonly ContaAzulConnectionService $connections,
        private readonly ImportacaoContaAzulService $importacao,
        private readonly ConciliacaoContaAzulService $conciliacao,
        private readonly ContaAzulLocalCreationService $criacaoLocal,
        private readonly ReconciliacaoContaAzulService $reconciliacao
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('auth')) {
            return $response;
        }

        $lojaId = $this->lojaId($request);
        $conexao = $this->connections->latestForLoja($lojaId);
        $permissoes = $this->permissoesContaAzul();

        if (!$conexao) {
            return response()->json([
                'conectado' => false,
                'conexao' => null,
                'permissoes' => $permissoes,
            ]);
        }

        return response()->json([
            'conectado' => $conexao->status === 'ativa',
            'conexao' => $conexao->only(['id', 'status', 'loja_id', 'ultimo_healthcheck_em', 'ultimo_erro', 'nome_externo', 'ambiente']),
            'permissoes' => $permissoes,
        ]);
    }

    public function pendencias(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('operacao')) {
            return $response;
        }

        $lojaId = $this->lojaId($request);

        return response()->json([
            'data' => $this->conciliacao->resumoPendencias($lojaId),
        ]);
    }

    public function pendenciasDetalhadas(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('operacao')) {
            return $response;
        }

        $lojaId = $this->lojaId($request);
        $statuses = $request->query('status', ['novo', 'pendente', 'conflito']);
        if (is_string($statuses)) {
            $statuses = array_filter(array_map('trim', explode(',', $statuses)));
        }
        if (!is_array($statuses)) {
            $statuses = ['novo', 'pendente', 'conflito'];
        }

        try {
            $result = $this->conciliacao->listarPendenciasDetalhadas(
                $lojaId,
                $request->query('entidade') ? (string) $request->query('entidade') : null,
                $statuses,
                (int) $request->query('per_page', $request->query('limit', 50)),
                (int) $request->query('page', 1),
                $request->query('bucket') ? (string) $request->query('bucket') : null
            );
        } catch (ContaAzulException $e) {
            return response()->json([
                'ok' => false,
                'mensagem' => $e->getMessage(),
                'reason' => $e->reason,
            ], 422);
        }

        return response()->json($result);
    }

    public function testarConexao(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('auth')) {
            return $response;
        }

        $lojaId = $this->lojaId($request);
        $conexao = $this->connections->latestForLoja($lojaId);
        if (!$conexao) {
            return response()->json(['ok' => false, 'mensagem' => 'Nenhuma conexão encontrada'], 404);
        }

        try {
            $ok = $this->connections->healthcheck($conexao);
        } catch (ContaAzulException $e) {
            return response()->json([
                'ok' => false,
                'mensagem' => $e->getMessage(),
                'reason' => $e->reason,
                'conexao' => $conexao->only(['id', 'status', 'loja_id', 'ultimo_healthcheck_em', 'ultimo_erro', 'nome_externo', 'ambiente']),
            ], 422);
        }

        $payload = [
            'ok' => $ok,
            'conexao' => $conexao->only(['id', 'status', 'loja_id', 'ultimo_healthcheck_em', 'ultimo_erro', 'nome_externo', 'ambiente']),
        ];

        if (!$ok) {
            $payload['mensagem'] = $conexao->ultimo_erro
                ? 'Teste de conexao com a Conta Azul falhou: ' . $conexao->ultimo_erro
                : 'Teste de conexao com a Conta Azul falhou.';

            return response()->json($payload, 422);
        }

        return response()->json($payload);
    }

    public function batches(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('operacao')) {
            return $response;
        }

        $perPage = max(1, min((int) $request->query('per_page', 10), 100));
        $q = ContaAzulImportBatch::query()->orderByDesc('id');
        if ($request->filled('loja_id')) {
            $q->where('loja_id', (int) $request->query('loja_id'));
        }

        $page = $q->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (ContaAzulImportBatch $batch) => $this->formatBatch($batch))->values(),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    public function syncLogs(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('operacao')) {
            return $response;
        }

        $perPage = max(1, min((int) $request->query('per_page', 10), 100));
        $q = ContaAzulSyncLog::query()->orderByDesc('id');
        if ($request->filled('loja_id')) {
            $q->where('loja_id', (int) $request->query('loja_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', (string) $request->query('status'));
        }
        if ($request->filled('tipo_entidade')) {
            $q->where('tipo_entidade', (string) $request->query('tipo_entidade'));
        }
        if ($request->filled('direcao')) {
            $q->where('direcao', (string) $request->query('direcao'));
        }

        $page = $q->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->values(),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    public function batchDetalhe(Request $request, int $id): JsonResponse
    {
        if ($response = $this->autorizar('operacao')) {
            return $response;
        }

        $batch = ContaAzulImportBatch::query()
            ->when($request->filled('loja_id'), fn ($q) => $q->where('loja_id', (int) $request->query('loja_id')))
            ->findOrFail($id);

        return response()->json([
            'data' => [
                'batch' => $this->formatBatch($batch),
                'registros' => $this->registrosDoBatch($batch),
            ],
        ]);
    }

    public function localLookup(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('operacao')) {
            return $response;
        }

        $entidade = (string) $request->query('entidade', '');
        $q = trim((string) $request->query('q', ''));
        $limit = max(1, min((int) $request->query('limit', 10), 20));

        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        try {
            $tipo = $this->mapLookupEntidade($entidade);
        } catch (\InvalidArgumentException) {
            return response()->json(['ok' => false, 'mensagem' => 'Entidade inválida'], 422);
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $numeric = preg_replace('/\D+/', '', $q) ?: null;

        $data = match ($tipo) {
            ContaAzulEntityType::PESSOA => $this->lookupPessoas($like, $numeric, $limit),
            ContaAzulEntityType::PRODUTO => $this->lookupProdutos($like, $limit),
            ContaAzulEntityType::VENDA => $this->lookupVendas($like, $numeric, $limit),
            ContaAzulEntityType::TITULO => $this->lookupContasReceber($like, $numeric, $limit),
            ContaAzulEntityType::CONTA_PAGAR => $this->lookupContasPagar($like, $numeric, $limit),
            ContaAzulEntityType::CONTA_FINANCEIRA => $this->lookupContasFinanceiras($like, $limit),
            ContaAzulEntityType::CATEGORIA_FINANCEIRA => $this->lookupCategoriasFinanceiras($like, $limit),
            ContaAzulEntityType::CENTRO_CUSTO => $this->lookupCentrosCusto($like, $limit),
            ContaAzulEntityType::FORMA_PAGAMENTO => $this->lookupFormasPagamento($like, $limit),
            default => [],
        };

        return response()->json(['data' => $data]);
    }

    public function registrarTokenManual(ContaAzulManualTokenRequest $request): JsonResponse
    {
        if ($response = $this->autorizar('auth')) {
            return $response;
        }

        $dados = $request->validated();
        $lojaId = isset($dados['loja_id']) ? (int) $dados['loja_id'] : null;

        try {
            $conexao = $this->connections->persistManualTokens($lojaId, $dados);
        } catch (ContaAzulException $e) {
            return response()->json([
                'ok' => false,
                'mensagem' => $e->getMessage(),
                'reason' => $e->reason,
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'conexao' => $conexao->only(['id', 'status', 'loja_id', 'ambiente', 'nome_externo', 'ultimo_healthcheck_em', 'ultimo_erro']),
        ]);
    }

    public function importar(Request $request, string $entidade): JsonResponse
    {
        if ($response = $this->autorizar('operacao')) {
            return $response;
        }

        $lojaId = $this->lojaId($request);
        $conexao = $this->connections->latestForLoja($lojaId);
        if (!$conexao) {
            return response()->json(['ok' => false, 'mensagem' => 'Nenhuma conexão'], 404);
        }

        try {
            $tipo = $this->mapEntidade($entidade);
        } catch (\InvalidArgumentException) {
            return response()->json(['ok' => false, 'mensagem' => 'Entidade inválida'], 422);
        }

        $res = $this->importacao->importarParaStaging($conexao, $tipo, $lojaId);

        return response()->json(['ok' => true, 'resultado' => $res]);
    }

    public function conciliar(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('operacao')) {
            return $response;
        }

        $lojaId = $this->lojaId($request);
        $res = $this->conciliacao->conciliarPessoas($lojaId);

        return response()->json(['ok' => true, 'resultado' => $res]);
    }

    public function conciliarEntidade(Request $request, string $entidade): JsonResponse
    {
        if ($response = $this->autorizar('operacao')) {
            return $response;
        }

        $lojaId = $this->lojaId($request);
        $res = match ($entidade) {
            'pessoas' => $this->conciliacao->conciliarPessoas($lojaId),
            'produtos' => $this->conciliacao->conciliarProdutos($lojaId),
            'vendas' => $this->conciliacao->conciliarVendas($lojaId),
            'titulos', 'financeiro' => $this->conciliacao->conciliarTitulos($lojaId),
            'contas-pagar', 'contas_pagar' => $this->conciliacao->conciliarContasPagar($lojaId),
            'parcelas' => $this->conciliacao->conciliarParcelas($lojaId),
            'baixas' => $this->conciliacao->conciliarBaixas($lojaId),
            'contas-financeiras', 'contas_financeiras' => $this->conciliacao->conciliarContasFinanceiras($lojaId),
            'saldos-contas-financeiras', 'saldos_contas_financeiras' => $this->conciliacao->conciliarSaldosContasFinanceiras($lojaId),
            'categorias-financeiras', 'categorias_financeiras' => $this->conciliacao->conciliarCategoriasFinanceiras($lojaId),
            'centros-custo', 'centros_custo' => $this->conciliacao->conciliarCentrosCusto($lojaId),
            'formas-pagamento', 'formas_pagamento' => $this->conciliacao->conciliarFormasPagamento($lojaId),
            'tudo' => $this->conciliacao->conciliarTudo($lojaId),
            default => null,
        };

        if ($res === null) {
            return response()->json(['ok' => false, 'mensagem' => 'Entidade inválida'], 422);
        }

        return response()->json(['ok' => true, 'resultado' => $res]);
    }

    public function resolverPendencia(Request $request, string $entidade, int $id): JsonResponse
    {
        if ($response = $this->autorizar('operacao')) {
            return $response;
        }

        $dados = $request->validate([
            'acao' => ['required', 'in:vincular,ignorar'],
            'id_local' => ['nullable', 'integer', 'min:1'],
            'codigo_externo' => ['nullable', 'string', 'max:120'],
            'observacao' => ['nullable', 'string', 'max:1000'],
            'loja_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $resultado = $this->conciliacao->resolverPendencia(
                $entidade,
                $id,
                $this->lojaId($request),
                (string) $dados['acao'],
                isset($dados['id_local']) ? (int) $dados['id_local'] : null,
                isset($dados['observacao']) ? (string) $dados['observacao'] : null,
                isset($dados['codigo_externo']) ? (string) $dados['codigo_externo'] : null
            );
        } catch (ContaAzulException $e) {
            return response()->json([
                'ok' => false,
                'mensagem' => $e->getMessage(),
                'reason' => $e->reason,
            ], 422);
        }

        return response()->json(['ok' => true, 'resultado' => $resultado]);
    }

    public function previewCriacaoLocal(Request $request, string $entidade, int $id): JsonResponse
    {
        if ($response = $this->autorizar('operacao')) {
            return $response;
        }

        try {
            $preview = $this->criacaoLocal->preview($entidade, $id, $this->lojaId($request));
        } catch (ContaAzulException $e) {
            return response()->json([
                'ok' => false,
                'mensagem' => $e->getMessage(),
                'reason' => $e->reason,
            ], 422);
        }

        return response()->json(['ok' => true, 'data' => $preview]);
    }

    public function criarRegistroLocal(Request $request, string $entidade, int $id): JsonResponse
    {
        if ($response = $this->autorizar('operacao')) {
            return $response;
        }

        $dados = $request->validate([
            'tipo_local' => ['required', 'string', 'max:50'],
            'dados' => ['required', 'array'],
            'pessoa' => ['nullable', 'array'],
            'baixa' => ['nullable', 'array'],
            'loja_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $resultado = $this->criacaoLocal->criarLocal($entidade, $id, $this->lojaId($request), $dados);
        } catch (ContaAzulException $e) {
            return response()->json([
                'ok' => false,
                'mensagem' => $e->getMessage(),
                'reason' => $e->reason,
            ], 422);
        }

        return response()->json(['ok' => true, 'resultado' => $resultado]);
    }

    public function criarRegistrosLocaisLote(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('operacao')) {
            return $response;
        }

        $modo = (string) $request->input('modo', 'itens');
        $dados = $request->validate([
            'modo' => ['nullable', 'in:itens,filtro'],
            'itens' => [$modo === 'itens' ? 'required' : 'nullable', 'array', 'min:1', 'max:100'],
            'itens.*.entidade' => ['required', 'string', 'max:80'],
            'itens.*.id' => ['required', 'integer', 'min:1'],
            'filtros' => [$modo === 'filtro' ? 'required' : 'nullable', 'array'],
            'filtros.status' => ['nullable'],
            'filtros.entidade' => ['nullable', 'string', 'max:80'],
            'filtros.bucket' => ['nullable', 'string', 'max:40'],
            'filtros.loja_id' => ['nullable', 'integer', 'min:1'],
            'loja_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $lojaId = $modo === 'filtro' && isset($dados['filtros']['loja_id'])
                ? (int) $dados['filtros']['loja_id']
                : $this->lojaId($request);
            $resultado = $modo === 'filtro'
                ? $this->criacaoLocal->criarLocalLotePorFiltro((array) ($dados['filtros'] ?? []), $lojaId)
                : $this->criacaoLocal->criarLocalLote($dados['itens'], $lojaId);
        } catch (ValidationException $e) {
            throw $e;
        } catch (ContaAzulException $e) {
            return response()->json([
                'ok' => false,
                'mensagem' => $e->getMessage(),
                'reason' => $e->reason,
            ], 422);
        }

        return response()->json(['ok' => true, 'resultado' => $resultado]);
    }

    public function reconciliar(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('operacao')) {
            return $response;
        }

        $lojaId = $this->lojaId($request);
        $conexao = $this->connections->latestForLoja($lojaId);
        if (!$conexao) {
            return response()->json(['ok' => false, 'mensagem' => 'Nenhuma conexão'], 404);
        }

        $recurso = (string) $request->input('recurso', 'pessoas');
        $this->reconciliacao->reconciliarRecurso($conexao, $recurso, $lojaId);

        return response()->json(['ok' => true]);
    }

    public function reconciliarTodos(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('operacao')) {
            return $response;
        }

        $lojaId = $this->lojaId($request);
        $conexao = $this->connections->latestForLoja($lojaId);
        if (!$conexao) {
            return response()->json(['ok' => false, 'mensagem' => 'Nenhuma conexão'], 404);
        }

        $this->reconciliacao->reconciliarTodos($conexao, $lojaId);

        return response()->json(['ok' => true]);
    }

    private function autorizar(string $escopo): ?JsonResponse
    {
        $permitido = match ($escopo) {
            'auth' => AuthHelper::podeAutenticarContaAzul(),
            'operacao' => AuthHelper::podeOperarContaAzul(),
            default => false,
        };

        if ($permitido) {
            return null;
        }

        return response()->json(['message' => 'Sem permissao para acessar a integracao Conta Azul.'], 403);
    }

    /**
     * @return array{auth: bool, operacao: bool, auditoria: bool}
     */
    private function permissoesContaAzul(): array
    {
        $podeOperar = AuthHelper::podeOperarContaAzul();

        return [
            'auth' => AuthHelper::podeAutenticarContaAzul(),
            'operacao' => $podeOperar,
            'auditoria' => $podeOperar,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function paginationMeta($page): array
    {
        return [
            'total' => (int) $page->total(),
            'page' => (int) $page->currentPage(),
            'per_page' => (int) $page->perPage(),
            'from' => (int) ($page->firstItem() ?? 0),
            'to' => (int) ($page->lastItem() ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatBatch(ContaAzulImportBatch $batch): array
    {
        $data = $batch->toArray();
        $seconds = null;
        if ($batch->iniciado_em && $batch->finalizado_em) {
            $seconds = max(0, $batch->iniciado_em->diffInSeconds($batch->finalizado_em));
        }

        return $data + [
            'duracao_segundos' => $seconds,
            'duracao_label' => $this->durationLabel($seconds),
            'total_falhas' => (int) ($batch->total_falhas ?? 0),
            'total_pendentes' => (int) ($batch->total_pendentes ?? 0),
        ];
    }

    private function durationLabel(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        return $remaining > 0 ? "{$minutes}min {$remaining}s" : "{$minutes}min";
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function registrosDoBatch(ContaAzulImportBatch $batch): array
    {
        $tables = [
            ContaAzulEntityType::PESSOA => 'stg_conta_azul_pessoas',
            ContaAzulEntityType::PRODUTO => 'stg_conta_azul_produtos',
            ContaAzulEntityType::VENDA => 'stg_conta_azul_vendas',
            ContaAzulEntityType::TITULO => 'stg_conta_azul_financeiro',
            ContaAzulEntityType::CONTA_PAGAR => 'stg_conta_azul_contas_pagar',
            ContaAzulEntityType::PARCELA => 'stg_conta_azul_parcelas',
            ContaAzulEntityType::BAIXA => 'stg_conta_azul_baixas',
            ContaAzulEntityType::NOTA => 'stg_conta_azul_notas',
            ContaAzulEntityType::CONTA_FINANCEIRA => 'stg_conta_azul_contas_financeiras',
            ContaAzulEntityType::SALDO_CONTA_FINANCEIRA => 'stg_conta_azul_saldos_contas_financeiras',
            ContaAzulEntityType::CATEGORIA_FINANCEIRA => 'stg_conta_azul_categorias_financeiras',
            ContaAzulEntityType::CENTRO_CUSTO => 'stg_conta_azul_centros_custo',
            ContaAzulEntityType::FORMA_PAGAMENTO => 'stg_conta_azul_formas_pagamento',
        ];

        $table = $tables[$batch->tipo_entidade] ?? null;
        if (!$table) {
            return [];
        }

        return DB::table($table)
            ->where('batch_id', $batch->id)
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'entidade' => $batch->tipo_entidade,
                'identificador_externo' => (string) $row->identificador_externo,
                'status_conciliacao' => (string) $row->status_conciliacao,
                'observacao_conciliacao' => $row->observacao_conciliacao,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,label:string,detail:string,type:string}>
     */
    private function lookupPessoas(string $like, ?string $numeric, int $limit): array
    {
        $clientes = DB::table('clientes')
            ->select(['id', 'nome', 'documento', 'email'])
            ->where(function ($query) use ($like, $numeric) {
                $query->where('nome', 'like', $like)
                    ->orWhere('email', 'like', $like);
                if ($numeric) {
                    $query->orWhere('documento', 'like', '%' . $numeric . '%');
                }
            })
            ->orderBy('nome')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'label' => (string) ($row->nome ?: 'Cliente #' . $row->id),
                'detail' => $this->joinDetails(['Cliente', $row->documento ? 'Doc. ' . $row->documento : null, $row->email]),
                'type' => 'cliente',
            ]);

        if ($clientes->count() >= $limit) {
            return $clientes->values()->all();
        }

        $fornecedores = DB::table('fornecedores')
            ->select(['id', 'nome', 'cnpj', 'email'])
            ->whereNull('deleted_at')
            ->where(function ($query) use ($like, $numeric) {
                $query->where('nome', 'like', $like)
                    ->orWhere('email', 'like', $like);
                if ($numeric) {
                    $query->orWhere('cnpj', 'like', '%' . $numeric . '%');
                }
            })
            ->orderBy('nome')
            ->limit($limit - $clientes->count())
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'label' => (string) ($row->nome ?: 'Fornecedor #' . $row->id),
                'detail' => $this->joinDetails(['Fornecedor', $row->cnpj ? 'CNPJ ' . $row->cnpj : null, $row->email]),
                'type' => 'fornecedor',
            ]);

        return $clientes->concat($fornecedores)->values()->all();
    }

    /**
     * @return array<int, array{id:int,label:string,detail:string,type:string}>
     */
    private function lookupProdutos(string $like, int $limit): array
    {
        return DB::table('produtos')
            ->select(['id', 'nome', 'codigo_produto'])
            ->where(function ($query) use ($like) {
                $query->where('nome', 'like', $like)
                    ->orWhere('codigo_produto', 'like', $like);
            })
            ->orderBy('nome')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'label' => (string) ($row->nome ?: 'Produto #' . $row->id),
                'detail' => $this->joinDetails(['Produto', $row->codigo_produto ? 'Código ' . $row->codigo_produto : null]),
                'type' => 'produto',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,label:string,detail:string,type:string}>
     */
    private function lookupVendas(string $like, ?string $numeric, int $limit): array
    {
        return DB::table('pedidos')
            ->leftJoin('clientes', 'clientes.id', '=', 'pedidos.id_cliente')
            ->select(['pedidos.id', 'pedidos.numero_externo', 'pedidos.valor_total', 'pedidos.data_pedido', 'clientes.nome as cliente_nome'])
            ->where(function ($query) use ($like, $numeric) {
                $query->where('pedidos.numero_externo', 'like', $like)
                    ->orWhere('clientes.nome', 'like', $like);
                if ($numeric) {
                    $query->orWhere('pedidos.id', (int) $numeric);
                }
            })
            ->orderByDesc('pedidos.id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'label' => 'Pedido #' . $row->id,
                'detail' => $this->joinDetails([
                    $row->numero_externo ? 'Número ' . $row->numero_externo : null,
                    $row->cliente_nome,
                    $row->valor_total !== null ? 'R$ ' . number_format((float) $row->valor_total, 2, ',', '.') : null,
                ]),
                'type' => 'pedido',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,label:string,detail:string,type:string}>
     */
    private function lookupContasReceber(string $like, ?string $numeric, int $limit): array
    {
        return DB::table('contas_receber')
            ->select(['id', 'descricao', 'numero_documento', 'valor_bruto', 'data_vencimento'])
            ->whereNull('deleted_at')
            ->where(function ($query) use ($like, $numeric) {
                $query->where('descricao', 'like', $like)
                    ->orWhere('numero_documento', 'like', $like);
                if ($numeric) {
                    $query->orWhere('id', (int) $numeric);
                }
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'label' => (string) ($row->descricao ?: 'Conta a receber #' . $row->id),
                'detail' => $this->joinDetails([
                    $row->numero_documento ? 'Doc. ' . $row->numero_documento : null,
                    $row->valor_bruto !== null ? 'R$ ' . number_format((float) $row->valor_bruto, 2, ',', '.') : null,
                    $row->data_vencimento ? 'Venc. ' . date('d/m/Y', strtotime((string) $row->data_vencimento)) : null,
                ]),
                'type' => 'conta_receber',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,label:string,detail:string,type:string}>
     */
    private function lookupContasPagar(string $like, ?string $numeric, int $limit): array
    {
        return DB::table('contas_pagar')
            ->leftJoin('fornecedores', 'fornecedores.id', '=', 'contas_pagar.fornecedor_id')
            ->select(['contas_pagar.id', 'contas_pagar.descricao', 'contas_pagar.numero_documento', 'contas_pagar.valor_bruto', 'contas_pagar.data_vencimento', 'fornecedores.nome as fornecedor_nome'])
            ->whereNull('contas_pagar.deleted_at')
            ->where(function ($query) use ($like, $numeric) {
                $query->where('contas_pagar.descricao', 'like', $like)
                    ->orWhere('contas_pagar.numero_documento', 'like', $like)
                    ->orWhere('fornecedores.nome', 'like', $like);
                if ($numeric) {
                    $query->orWhere('contas_pagar.id', (int) $numeric);
                }
            })
            ->orderByDesc('contas_pagar.id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'label' => (string) ($row->descricao ?: 'Conta a pagar #' . $row->id),
                'detail' => $this->joinDetails([
                    $row->fornecedor_nome,
                    $row->numero_documento ? 'Doc. ' . $row->numero_documento : null,
                    $row->valor_bruto !== null ? 'R$ ' . number_format((float) $row->valor_bruto, 2, ',', '.') : null,
                ]),
                'type' => 'conta_pagar',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,label:string,detail:string,type:string}>
     */
    private function lookupContasFinanceiras(string $like, int $limit): array
    {
        return DB::table('contas_financeiras')
            ->select(['id', 'nome', 'tipo', 'banco_nome', 'agencia', 'conta'])
            ->where(function ($query) use ($like) {
                $query->where('nome', 'like', $like)
                    ->orWhere('banco_nome', 'like', $like)
                    ->orWhere('conta', 'like', $like);
            })
            ->orderBy('nome')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'label' => (string) ($row->nome ?: 'Conta financeira #' . $row->id),
                'detail' => $this->joinDetails([$row->tipo, $row->banco_nome, $row->agencia ? 'Ag. ' . $row->agencia : null, $row->conta ? 'Conta ' . $row->conta : null]),
                'type' => 'conta_financeira',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,label:string,detail:string,type:string}>
     */
    private function lookupCategoriasFinanceiras(string $like, int $limit): array
    {
        return DB::table('categorias_financeiras')
            ->select(['id', 'nome', 'tipo', 'ativo'])
            ->where('nome', 'like', $like)
            ->orderBy('nome')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'label' => (string) ($row->nome ?: 'Categoria #' . $row->id),
                'detail' => $this->joinDetails(['Categoria financeira', $row->tipo, $row->ativo ? 'Ativa' : 'Inativa']),
                'type' => 'categoria_financeira',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,label:string,detail:string,type:string}>
     */
    private function lookupCentrosCusto(string $like, int $limit): array
    {
        return DB::table('centros_custo')
            ->select(['id', 'nome', 'ativo'])
            ->where('nome', 'like', $like)
            ->orderBy('nome')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'label' => (string) ($row->nome ?: 'Centro de custo #' . $row->id),
                'detail' => $this->joinDetails(['Centro de custo', $row->ativo ? 'Ativo' : 'Inativo']),
                'type' => 'centro_custo',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,label:string,detail:string,type:string}>
     */
    private function lookupFormasPagamento(string $like, int $limit): array
    {
        return DB::table('formas_pagamento')
            ->select(['id', 'nome', 'slug', 'ativo'])
            ->where(function ($query) use ($like) {
                $query->where('nome', 'like', $like)
                    ->orWhere('slug', 'like', $like);
            })
            ->orderBy('nome')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'label' => (string) ($row->nome ?: 'Forma de pagamento #' . $row->id),
                'detail' => $this->joinDetails(['Forma de pagamento', $row->slug, $row->ativo ? 'Ativa' : 'Inativa']),
                'type' => 'forma_pagamento',
            ])
            ->values()
            ->all();
    }

    private function joinDetails(array $parts): string
    {
        return implode(' - ', array_values(array_filter(array_map(
            fn ($part) => is_scalar($part) ? trim((string) $part) : '',
            $parts
        ))));
    }

    private function lojaId(Request $request): ?int
    {
        $v = $request->query('loja_id', $request->input('loja_id'));

        return $v === null || $v === '' ? null : (int) $v;
    }

    private function mapEntidade(string $entidade): string
    {
        return match ($entidade) {
            'pessoas' => ContaAzulEntityType::PESSOA,
            'produtos' => ContaAzulEntityType::PRODUTO,
            'vendas' => ContaAzulEntityType::VENDA,
            'financeiro' => ContaAzulEntityType::TITULO,
            'contas-receber' => ContaAzulEntityType::TITULO,
            'contas_pagar', 'contas-pagar' => ContaAzulEntityType::CONTA_PAGAR,
            'parcelas' => ContaAzulEntityType::PARCELA,
            'baixas' => ContaAzulEntityType::BAIXA,
            'contas_financeiras', 'contas-financeiras', 'conta-financeira' => ContaAzulEntityType::CONTA_FINANCEIRA,
            'saldos_contas_financeiras', 'saldos-contas-financeiras' => ContaAzulEntityType::SALDO_CONTA_FINANCEIRA,
            'categorias_financeiras', 'categorias-financeiras', 'categoria-financeira', 'categorias' => ContaAzulEntityType::CATEGORIA_FINANCEIRA,
            'centros_custo', 'centros-custo', 'centro-de-custo' => ContaAzulEntityType::CENTRO_CUSTO,
            'formas_pagamento', 'formas-pagamento', 'formas-de-pagamento' => ContaAzulEntityType::FORMA_PAGAMENTO,
            'notas' => ContaAzulEntityType::NOTA,
            default => throw new \InvalidArgumentException('Entidade inválida'),
        };
    }

    private function mapLookupEntidade(string $entidade): string
    {
        return match (strtolower(trim($entidade))) {
            'pessoa', 'pessoas' => ContaAzulEntityType::PESSOA,
            'produto', 'produtos' => ContaAzulEntityType::PRODUTO,
            'venda', 'vendas' => ContaAzulEntityType::VENDA,
            'titulo', 'titulos', 'financeiro' => ContaAzulEntityType::TITULO,
            'conta_pagar', 'contas_pagar', 'contas-pagar' => ContaAzulEntityType::CONTA_PAGAR,
            'parcela', 'parcelas' => ContaAzulEntityType::PARCELA,
            'baixa', 'baixas' => ContaAzulEntityType::BAIXA,
            'conta_financeira', 'contas_financeiras', 'contas-financeiras', 'conta-financeira' => ContaAzulEntityType::CONTA_FINANCEIRA,
            'saldo_conta_financeira', 'saldo-conta-financeira', 'saldos-contas-financeiras', 'saldos_contas_financeiras' => ContaAzulEntityType::SALDO_CONTA_FINANCEIRA,
            'categoria_financeira', 'categorias_financeiras', 'categorias-financeiras', 'categoria-financeira', 'categoria', 'categorias' => ContaAzulEntityType::CATEGORIA_FINANCEIRA,
            'centro_custo', 'centros_custo', 'centros-custo', 'centro-de-custo' => ContaAzulEntityType::CENTRO_CUSTO,
            'forma_pagamento', 'formas_pagamento', 'formas-pagamento', 'formas-de-pagamento' => ContaAzulEntityType::FORMA_PAGAMENTO,
            default => throw new \InvalidArgumentException('Entidade invalida'),
        };
    }
}
