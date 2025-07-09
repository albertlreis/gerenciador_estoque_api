<?php

namespace App\Services;

use App\Exports\PedidosExport;
use App\Models\Pedido;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Maatwebsite\Excel\Facades\Excel;

class PedidoExportService
{
    /**
     * Exporta pedidos em PDF ou Excel.
     *
     * @param Request $request
     * @return Response|BinaryFileResponse|JsonResponse
     */
    public function exportar(Request $request): Response|BinaryFileResponse|JsonResponse
    {
        $formato = $request->query('formato');
        $detalhado = $request->boolean('detalhado', false);

        $pedidos = Pedido::with(['cliente', 'parceiro'])->get();

        if ($formato === 'excel') {
            return Excel::download(new PedidosExport($pedidos), 'pedidos.xlsx');
        }

        if ($formato === 'pdf') {
            $view = $detalhado ? 'exports.pedidos-pdf-detalhado' : 'exports.pedidos-pdf';
            $pdf = Pdf::loadView($view, ['pedidos' => $pedidos]);
            return $pdf->download($detalhado ? 'pedidos-detalhado.pdf' : 'pedidos.pdf');
        }

        return response()->json(['erro' => 'Formato inválido'], 400);
    }
}
