<?php

namespace App\Http\Controllers;

use App\Models\EstoqueTransferencia;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class EstoqueTransferenciaController extends Controller
{
    public function index(Request $request)
    {
        $q = EstoqueTransferencia::query()
            ->with(['depositoOrigem', 'depositoDestino', 'usuario'])
            ->orderByDesc('id');

        if ($request->filled('deposito_id')) {
            $dep = (int) $request->input('deposito_id');
            $q->where(function ($w) use ($dep) {
                $w->where('deposito_origem_id', $dep)->orWhere('deposito_destino_id', $dep);
            });
        }

        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        return response()->json($q->paginate((int)($request->input('perPage', 15))));
    }

    public function show(EstoqueTransferencia $transferencia)
    {
        $transferencia->load([
            'depositoOrigem',
            'depositoDestino',
            'usuario',
            'itens.variacao.produto',
            'itens.variacao.atributos',
        ]);

        return response()->json($transferencia);
    }

    public function pdf(EstoqueTransferencia $transferencia)
    {
        $transferencia->load([
            'depositoOrigem',
            'depositoDestino',
            'usuario',
            'itens.variacao.produto',
        ]);

        $pdf = Pdf::loadView('exports.transferencia-deposito', [
            'transferencia' => $transferencia
        ])->setPaper('a4', 'portrait');

        return $pdf->download("transferencia-{$transferencia->uuid}.pdf");
    }
}
