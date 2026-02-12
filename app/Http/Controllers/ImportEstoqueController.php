<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Requests\ImportEstoqueUploadRequest;
use App\Models\EstoqueImport;
use App\Services\Import\EstoqueImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportEstoqueController extends Controller
{
    public function __construct(private readonly EstoqueImportService $service) {}

    private function autorizarSomenteDev(): void
    {
        abort_unless(
            AuthHelper::podeImportarEstoquePlanilhaDev(),
            403,
            'Acesso permitido apenas para desenvolvedor.'
        );
    }

    /** POST /imports/estoque (upload + staging) */
    public function store(ImportEstoqueUploadRequest $request): JsonResponse
    {
        $this->autorizarSomenteDev();

        set_time_limit(600);
        ini_set('memory_limit', '1G');

        $import = $this->service->criarStaging($request->file('arquivo'), auth()->id());
        $linhasComErro = $import->rows()
            ->where('valido', false)
            ->orderBy('linha_planilha')
            ->limit(200)
            ->get(['linha_planilha', 'nome', 'cod', 'categoria', 'status', 'erros', 'warnings']);

        return response()->json([
            'sucesso' => true,
            'mensagem' => 'Arquivo recebido e linhas enviadas para staging.',
            'data' => [
                'import_id' => $import->id,
                'status' => $import->status,
                'linhas_total' => $import->linhas_total,
                'linhas_validas' => $import->linhas_validas,
                'linhas_invalidas' => $import->linhas_invalidas,
                'metricas' => $import->metricas,
            ],
            'erros' => $linhasComErro->map(fn ($r) => [
                'linha' => (int) $r->linha_planilha,
                'nome' => $r->nome,
                'referencia' => $r->cod,
                'categoria' => $r->categoria,
                'status' => $r->status,
                'erros' => is_array($r->erros) ? $r->erros : [],
                'warnings' => is_array($r->warnings) ? $r->warnings : [],
            ])->values(),
        ]);
    }

    /** POST /imports/estoque/{id}/processar?dry_run=1 */
    public function processar(Request $request, int $id): JsonResponse
    {
        $this->autorizarSomenteDev();

        set_time_limit(600);

        $import = EstoqueImport::findOrFail($id);
        $dry = $request->boolean('dry_run', false);

        $res = $this->service->processar($import, $dry);

        return response()->json($res, $res['sucesso'] ? 200 : 422);
    }

    /** GET /imports/estoque/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->autorizarSomenteDev();

        $import = EstoqueImport::withCount('rows')->findOrFail($id);
        $includeRows = $request->boolean('include_rows', false);

        $payload = [
            'import' => $import,
        ];

        if ($includeRows) {
            $payload['linhas_com_erro'] = $import->rows()
                ->where('valido', false)
                ->orderBy('linha_planilha')
                ->limit(500)
                ->get(['linha_planilha', 'nome', 'cod', 'categoria', 'status', 'erros', 'warnings']);
        }

        return response()->json([
            'sucesso' => true,
            'data' => $payload
        ]);
    }
}
