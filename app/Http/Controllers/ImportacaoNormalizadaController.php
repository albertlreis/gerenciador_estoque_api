<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Requests\AtualizarRevisaoImportacaoNormalizadaConflitoRequest;
use App\Http\Requests\AtualizarRevisaoImportacaoNormalizadaLinhaRequest;
use App\Http\Requests\ConfirmarImportacaoNormalizadaRequest;
use App\Http\Requests\EfetivarImportacaoNormalizadaRequest;
use App\Http\Requests\ImportacaoNormalizadaUploadRequest;
use App\Models\ImportacaoNormalizada;
use App\Models\ImportacaoNormalizadaConflito;
use App\Models\ImportacaoNormalizadaLinha;
use App\Services\Import\ImportacaoNormalizadaPipelineService;
use App\Services\Import\ImportacaoNormalizadaSierraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportacaoNormalizadaController extends Controller
{
    public function __construct(
        private readonly ImportacaoNormalizadaSierraService $service,
        private readonly ImportacaoNormalizadaPipelineService $pipelineService,
    ) {}

    public function store(ImportacaoNormalizadaUploadRequest $request): JsonResponse
    {
        $this->prepararAmbienteImportacao();

        $modoCargaInicial = $request->boolean('modo_carga_inicial', false);
        $importacao = $this->service->criarStaging($request->file('arquivo'), auth()->id(), $modoCargaInicial);

        return response()->json([
            'sucesso' => true,
            'mensagem' => 'Planilha recebida e enviada para staging normalizado.',
            'data' => $this->serializarImportacao($importacao->loadCount(['linhas', 'conflitos'])),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->autorizarSomenteDev();

        $importacao = ImportacaoNormalizada::query()
            ->withCount(['linhas', 'conflitos'])
            ->findOrFail($id);

        $payload = [
            'importacao' => $this->serializarImportacao($importacao),
        ];

        if ($request->boolean('include_rows', false)) {
            $payload['linhas'] = ImportacaoNormalizadaLinha::query()
                ->where('importacao_id', $importacao->id)
                ->orderBy('aba_origem')
                ->orderBy('linha_planilha')
                ->limit((int) $request->integer('rows_limit', 500))
                ->get();
        }

        if ($request->boolean('include_conflicts', false)) {
            $payload['conflitos'] = ImportacaoNormalizadaConflito::query()
                ->where('importacao_id', $importacao->id)
                ->orderByDesc('severidade')
                ->latest('id')
                ->limit((int) $request->integer('conflicts_limit', 500))
                ->get();
        }

        return response()->json([
            'sucesso' => true,
            'data' => $payload,
        ]);
    }

    public function preview(Request $request, int $id): JsonResponse
    {
        $this->autorizarSomenteDev();

        $importacao = ImportacaoNormalizada::query()->findOrFail($id);
        $preview = $this->pipelineService->gerarPreview($importacao, true);

        $payload = [
            'importacao' => $this->serializarImportacao($importacao->fresh()),
            'preview' => $preview,
        ];

        if ($request->boolean('include_rows', false)) {
            $payload['linhas'] = ImportacaoNormalizadaLinha::query()
                ->where('importacao_id', $importacao->id)
                ->orderBy('aba_origem')
                ->orderBy('linha_planilha')
                ->limit((int) $request->integer('rows_limit', 200))
                ->get();
        }

        return response()->json([
            'sucesso' => true,
            'data' => $payload,
        ]);
    }

    public function linhas(Request $request, int $id): JsonResponse
    {
        $this->autorizarSomenteDev();

        $query = ImportacaoNormalizadaLinha::query()
            ->where('importacao_id', $id)
            ->orderBy('aba_origem')
            ->orderBy('linha_planilha');

        if ($statusRevisao = $request->input('status_revisao')) {
            $query->where('status_revisao', $statusRevisao);
        }
        if ($statusProcessamento = $request->input('status_processamento')) {
            $query->where('status_processamento', $statusProcessamento);
        }
        if ($sheet = $request->input('aba_origem')) {
            $query->where('aba_origem', $sheet);
        }
        if ($status = $request->input('status_normalizado', $request->input('status'))) {
            $query->where('status_normalizado', $status);
        }
        if ($classificacao = $request->input('classificacao_acao')) {
            $query->where('classificacao_acao', $classificacao);
        }
        if ($request->has('gera_estoque')) {
            $query->where('gera_estoque', $request->boolean('gera_estoque'));
        }
        if ($request->has('gera_movimentacao')) {
            $query->where('gera_movimentacao', $request->boolean('gera_movimentacao'));
        }
        if ($request->boolean('apenas_bloqueadas', false)) {
            $query->whereIn('status_processamento', ['bloqueada', 'pendente_revisao', 'erro']);
        }

        return response()->json([
            'sucesso' => true,
            'data' => $query->paginate($request->integer('per_page', 50)),
        ]);
    }

    public function conflitos(Request $request, int $id): JsonResponse
    {
        $this->autorizarSomenteDev();

        $query = ImportacaoNormalizadaConflito::query()
            ->where('importacao_id', $id)
            ->orderByDesc('severidade')
            ->orderByDesc('id');

        if ($statusRevisao = $request->input('status_revisao')) {
            $query->where('status_revisao', $statusRevisao);
        }
        if ($severidade = $request->input('severidade')) {
            $query->where('severidade', $severidade);
        }
        if ($request->boolean('apenas_pendentes', false)) {
            $query->where('status_revisao', 'pendente_revisao');
        }

        return response()->json([
            'sucesso' => true,
            'data' => $query->paginate($request->integer('per_page', 50)),
        ]);
    }

    public function pendencias(Request $request, int $id): JsonResponse
    {
        $this->autorizarSomenteDev();

        $query = ImportacaoNormalizadaLinha::query()
            ->where('importacao_id', $id)
            ->where(function ($q) {
                $q->whereIn('status_processamento', ['pendente_revisao', 'bloqueada', 'erro'])
                    ->orWhere('status_revisao', 'pendente_revisao');
            })
            ->orderBy('aba_origem')
            ->orderBy('linha_planilha');

        if ($sheet = $request->input('aba_origem')) {
            $query->where('aba_origem', $sheet);
        }
        if ($classificacao = $request->input('classificacao_acao')) {
            $query->where('classificacao_acao', $classificacao);
        }

        return response()->json([
            'sucesso' => true,
            'data' => $query->paginate($request->integer('per_page', 50)),
        ]);
    }

    public function revisarLinha(
        AtualizarRevisaoImportacaoNormalizadaLinhaRequest $request,
        ImportacaoNormalizadaLinha $linha
    ): JsonResponse {
        $this->autorizarSomenteDev();

        $linha = $this->service->revisarLinha($linha, $request->validated(), auth()->id());

        return response()->json([
            'sucesso' => true,
            'mensagem' => 'Revisão da linha registrada.',
            'data' => $linha,
        ]);
    }

    public function revisarConflito(
        AtualizarRevisaoImportacaoNormalizadaConflitoRequest $request,
        ImportacaoNormalizadaConflito $conflito
    ): JsonResponse {
        $this->autorizarSomenteDev();

        $conflito = $this->service->revisarConflito($conflito, $request->validated(), auth()->id());

        return response()->json([
            'sucesso' => true,
            'mensagem' => 'Revisão do conflito registrada.',
            'data' => $conflito,
        ]);
    }

    public function confirmar(ConfirmarImportacaoNormalizadaRequest $request, int $id): JsonResponse
    {
        $this->prepararAmbienteImportacao();

        $importacao = ImportacaoNormalizada::query()->findOrFail($id);
        $resultado = $this->pipelineService->confirmar($importacao, auth()->id(), $request->boolean('modo_carga_inicial', false));

        return response()->json($resultado, $resultado['sucesso'] ? 200 : 422);
    }

    public function efetivar(EfetivarImportacaoNormalizadaRequest $request, int $id): JsonResponse
    {
        $this->prepararAmbienteImportacao();

        $importacao = ImportacaoNormalizada::query()->findOrFail($id);
        $resultado = $this->pipelineService->efetivar($importacao, auth()->id(), $request->boolean('modo_carga_inicial', false));

        return response()->json($resultado, $resultado['sucesso'] ? 200 : 422);
    }

    public function relatorio(int $id): JsonResponse
    {
        $this->autorizarSomenteDev();

        $importacao = ImportacaoNormalizada::query()->findOrFail($id);

        return response()->json([
            'sucesso' => true,
            'data' => [
                'importacao' => $this->serializarImportacao($importacao),
                'preview' => $importacao->preview_resumo,
                'relatorio' => $importacao->relatorio_final,
            ],
        ]);
    }

    private function autorizarSomenteDev(): void
    {
        abort_unless(
            AuthHelper::podeImportarEstoquePlanilhaDev(),
            403,
            'Acesso permitido apenas para desenvolvedor.'
        );
    }

    private function prepararAmbienteImportacao(): void
    {
        $this->autorizarSomenteDev();

        ignore_user_abort(true);
        @ini_set('memory_limit', '2048M');
        @ini_set('max_execution_time', '0');
        @ini_set('max_input_time', '0');
        @set_time_limit(0);
    }

    private function serializarImportacao(ImportacaoNormalizada $importacao): array
    {
        return [
            'id' => $importacao->id,
            'tipo' => $importacao->tipo,
            'arquivo_nome' => $importacao->arquivo_nome,
            'arquivo_hash' => $importacao->arquivo_hash,
            'usuario_id' => $importacao->usuario_id,
            'status' => $importacao->status?->value ?? $importacao->status,
            'abas_processadas' => $importacao->abas_processadas,
            'linhas_total' => $importacao->linhas_total,
            'linhas_staged' => $importacao->linhas_staged,
            'linhas_com_conflito' => $importacao->linhas_com_conflito,
            'linhas_pendentes_revisao' => $importacao->linhas_pendentes_revisao,
            'linhas_com_erro' => $importacao->linhas_com_erro,
            'metricas' => $importacao->metricas,
            'preview_resumo' => $importacao->preview_resumo,
            'relatorio_final' => $importacao->relatorio_final,
            'confirmado_em' => $importacao->confirmado_em,
            'confirmado_por' => $importacao->confirmado_por,
            'efetivado_em' => $importacao->efetivado_em,
            'efetivado_por' => $importacao->efetivado_por,
            'chave_execucao' => $importacao->chave_execucao,
            'observacoes' => $importacao->observacoes,
            'linhas_count' => $importacao->linhas_count ?? null,
            'conflitos_count' => $importacao->conflitos_count ?? null,
            'created_at' => $importacao->created_at,
            'updated_at' => $importacao->updated_at,
        ];
    }
}
