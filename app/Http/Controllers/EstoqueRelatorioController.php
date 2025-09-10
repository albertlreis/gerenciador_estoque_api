<?php

namespace App\Http\Controllers;

use App\Exports\Relatorios\EstoqueAtualExport;
use App\Services\Relatorios\EstoqueRelatorioService;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EstoqueRelatorioController extends Controller
{
    protected EstoqueRelatorioService $service;

    public function __construct(EstoqueRelatorioService $service)
    {
        $this->service = $service;
    }

    /**
     * Relatório de Estoque Atual (JSON | PDF | Excel).
     *
     * @param  Request  $request
     * @return Response|JsonResponse|BinaryFileResponse
     */
    // app/Http/Controllers/EstoqueRelatorioController.php

// ...
    public function estoqueAtual(Request $request): Response|JsonResponse|BinaryFileResponse
    {
        $dados   = $this->service->obterEstoqueAtual($request->all());
        $formato = $request->query('formato');

        if ($formato === 'pdf') {
            $folder = env('PRODUCT_IMAGES_FOLDER', 'produtos');

             $baseFsDir = storage_path('app/public/' . $folder);

            $options = [
                'isRemoteEnabled' => false,
                'chroot'          => $baseFsDir,
            ];

            $pdf = Pdf::loadView('exports.estoque-atual', [
                'dados'     => $dados,
                'baseFsDir' => $baseFsDir,
            ]);

            $pdf->setOptions($options);

            @ini_set('max_execution_time', '120');
            @ini_set('memory_limit', '512M');

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
