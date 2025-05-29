<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConsignacaoDetalhadaResource;
use App\Http\Resources\ConsignacaoResource;
use App\Models\Consignacao;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConsignacaoController extends Controller
{

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Consignacao::with(['pedido.cliente', 'produtoVariacao.produto']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('cliente')) {
            $cliente = $request->cliente;
            $query->whereHas('pedido.cliente', function ($q) use ($cliente) {
                $q->where('nome', 'like', "%$cliente%");
            });
        }

        if ($request->filled('produto')) {
            $produto = $request->produto;
            $query->whereHas('produtoVariacao.produto', function ($q) use ($produto) {
                $q->where('nome', 'like', "%$produto%");
            });
        }

        if ($request->filled('vencimento_proximo')) {
            $query->whereDate('prazo_resposta', '<=', now()->addDays(3));
        }

        $query->orderBy('prazo_resposta');

        return ConsignacaoResource::collection(
            $query->paginate($request->get('per_page', 10))
        );
    }

    public function show($id): JsonResponse
    {
        $consignacao = Consignacao::with(['pedido.cliente', 'produtoVariacao.produto', 'produtoVariacao.atributos'])
            ->findOrFail($id);

        return response()->json(new ConsignacaoDetalhadaResource($consignacao));
    }

    public function atualizarStatus($id, Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:pendente,comprado,devolvido',
        ]);

        $consignacao = Consignacao::findOrFail($id);

        // Se já finalizada, não permitir alteração
        if (in_array($consignacao->status, ['aceita', 'devolvida'])) {
            return response()->json(['erro' => 'Consignação já finalizada.'], 422);
        }

        $consignacao->status = $request->status;
        $consignacao->data_resposta = now();
        $consignacao->save();

        return response()->json([
            'mensagem' => 'Status atualizado com sucesso.',
            'consignacao' => new ConsignacaoDetalhadaResource($consignacao->fresh(['pedido.cliente', 'produtoVariacao.produto'])),
        ]);
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
