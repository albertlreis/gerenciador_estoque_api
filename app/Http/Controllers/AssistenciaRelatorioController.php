<?php

namespace App\Http\Controllers;

use App\Exports\Relatorios\AssistenciasExport;
use App\Http\Requests\Relatorios\AssistenciaRelatorioRequest;
use App\Services\Relatorios\AssistenciaRelatorioService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * @group Relatórios: Assistências
 *
 * Endpoints relacionados aos relatórios de chamados de assistência
 */
class AssistenciaRelatorioController extends Controller
{
    public function __construct(
        private readonly AssistenciaRelatorioService $service
    ) {}

    /**
     * GET /relatorios/assistencias
     *
     * Filtros:
     * - status: string (AssistenciaStatus)
     * - abertura_inicio, abertura_fim (YYYY-MM-DD) => created_at do chamado
     * - conclusao_inicio, conclusao_fim (YYYY-MM-DD) => data calculada via logs (status final)
     * - locais_reparo[]: string[] (LocalReparo)
     * - custo_resp: string (CustoResponsavel)  // cliente|loja
     *
     * Saída:
     * - formato=pdf | excel | vazio (json)
     */
    public function assistencias(AssistenciaRelatorioRequest $request): Response|JsonResponse|BinaryFileResponse
    {
        $filtros = $request->validated();

        [$linhas, $totais] = $this->service->listar($filtros);

        $formato = $request->query('formato');

        if ($formato === 'pdf') {
            $pdf = Pdf::loadView('exports.assistencias', [
                'dados'   => $linhas,
                'totais'  => $totais,
                'filtros' => $filtros,
            ])->setPaper('a4', 'landscape');

            return $pdf->download('relatorio-assistencias.pdf');
        }

        if ($formato === 'excel') {
            return Excel::download(
                new AssistenciasExport($linhas, $totais),
                'relatorio-assistencias.xlsx'
            );
        }

        return response()->json([
            'data'   => $linhas,
            'totais' => $totais,
        ]);
    }
}
