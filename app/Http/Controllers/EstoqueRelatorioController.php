<?php

namespace App\Http\Controllers;

use App\Exports\Relatorios\EstoqueAtualExport;
use App\Services\PdfImageService;
use App\Services\Relatorios\EstoqueRelatorioService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * @group Relatórios: Estoque
 *
 * Endpoints relacionados aos relatórios de estoque
 */
class EstoqueRelatorioController extends Controller
{
    protected EstoqueRelatorioService $service;

    public function __construct(EstoqueRelatorioService $service)
    {
        $this->service = $service;
    }

    /**
     * Relatório de Estoque Atual
     *
     * @queryParam formato string Opcional. 'pdf' | 'excel'. Padrão: JSON.
     */
    public function estoqueAtual(Request $request): Response|JsonResponse|BinaryFileResponse
    {
        $dados = $this->service->obterEstoqueAtual($request->all());
        $formato = strtolower((string) $request->query('formato'));

        if ($formato === 'pdf') {
            Pdf::setOptions(['isRemoteEnabled' => true]);

            $geradoEm = now('America/Belem')->format('d/m/Y H:i');
            $pdfImageService = app(PdfImageService::class);
            $dados = collect($dados)
                ->map(function (array $info) use ($pdfImageService): array {
                    $info['imagem_principal_pdf'] = $pdfImageService->toPdfSrc($info['imagem_principal'] ?? null);
                    return $info;
                })
                ->all();

            $pdf = Pdf::loadView('exports.estoque-atual', [
                'dados'     => $dados,
                'geradoEm'  => $geradoEm,
            ]);

            return $pdf->download('relatorio-estoque.pdf');
        }

        if ($formato === 'excel') {
            return Excel::download(
                new EstoqueAtualExport($dados),
                'relatorio-estoque.xlsx'
            );
        }

        return response()->json(['data' => $dados]);
    }
}
