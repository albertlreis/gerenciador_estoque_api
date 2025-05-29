<?php

namespace App\Http\Controllers;

use App\Models\Consignacao;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsignacaoController extends Controller
{

    public function index(): Collection|array
    {
        return Consignacao::with('pedido', 'produtoVariacao')->get();
    }

    public function atualizarStatus($id, Request $request): JsonResponse
    {
        $consignacao = Consignacao::findOrFail($id);
        $consignacao->status = $request->status;
        $consignacao->save();

        return response()->json(['message' => 'Status atualizado com sucesso']);
    }

    public function vencendo(): Collection|array
    {
        return Consignacao::with('produtoVariacao')
            ->where('status', 'pendente')
            ->whereDate('prazo_resposta', '<=', now()->addDays(2))
            ->orderBy('prazo_resposta')
            ->get();
    }

}
