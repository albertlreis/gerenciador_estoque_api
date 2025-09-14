<?php

namespace App\Http\Controllers;

use App\Exports\Relatorios\EstoqueAtualExport;
use App\Services\Relatorios\EstoqueRelatorioService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Models\ProdutoImagem; // <- usaremos a pasta FOLDER

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
            // caminho físico DENTRO de /public, respeitando o chroot do DomPDF
            $baseFsDir = public_path('storage/' . ProdutoImagem::FOLDER);
            // habilita recursos remotos caso opte por usar URLs absolutas
            Pdf::setOptions(['isRemoteEnabled' => true]);

            $pdf = Pdf::loadView('exports.estoque-atual', [
                'dados'      => $dados,
                'baseFsDir'  => $baseFsDir, // <- passamos para o Blade
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
