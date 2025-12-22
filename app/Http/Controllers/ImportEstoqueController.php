<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportEstoqueUploadRequest;
use App\Models\EstoqueImport;
use App\Services\Import\EstoqueImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportEstoqueController extends Controller
{
    public function __construct(private readonly EstoqueImportService $service) {}

    /** POST /imports/estoque (upload + staging) */
    public function store(ImportEstoqueUploadRequest $request): JsonResponse
    {
        $import = $this->service->criarStaging($request->file('arquivo'), auth()->id());

        return response()->json([
            'sucesso' => true,
            'mensagem' => 'Arquivo recebido e linhas enviadas para staging.',
            'data' => [
                'import_id' => $import->id,
                'status' => $import->status,
                'linhas_total' => $import->linhas_total,
                'linhas_validas' => $import->linhas_validas,
                'linhas_invalidas' => $import->linhas_invalidas,
            ]
        ]);
    }

    /** POST /imports/estoque/{id}/processar?dry_run=1 */
    public function processar(Request $request, int $id): JsonResponse
    {
        set_time_limit(600);

        $import = EstoqueImport::findOrFail($id);
        $dry = $request->boolean('dry_run', false);

        $res = $this->service->processar($import, $dry);

        return response()->json($res, $res['sucesso'] ? 200 : 422);
    }

    /** GET /imports/estoque/{id} */
    public function show(int $id): JsonResponse
    {
        $import = EstoqueImport::withCount('rows')->findOrFail($id);
        return response()->json([
            'sucesso' => true,
            'data' => $import
        ]);
    }
}
