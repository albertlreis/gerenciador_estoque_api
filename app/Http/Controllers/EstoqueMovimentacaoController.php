<?php

namespace App\Http\Controllers;

use App\Http\Resources\MovimentacaoResource;
use App\Models\Produto;
use App\Models\EstoqueMovimentacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstoqueMovimentacaoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EstoqueMovimentacao::with([
            'variacao.produto', 'variacao.atributos', 'usuario', 'depositoOrigem', 'depositoDestino'
        ]);

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        if ($request->filled('produto')) {
            $query->whereHas('produto', fn($q) =>
            $q->where('nome', 'like', "%{$request->produto}%")
                ->orWhere('referencia', 'like', "%{$request->produto}%")
            );
        }

        if ($request->filled('deposito')) {
            $query->where(function ($q) use ($request) {
                $q->where('id_deposito_origem', $request->deposito)
                    ->orWhere('id_deposito_destino', $request->deposito);
            });
        }

        if ($request->filled('periodo')) {
            $inicio = $request->periodo[0];
            $fim = $request->periodo[1];
            $query->whereBetween('data_movimentacao', [$inicio, $fim]);
        }

        return response()->json(
            MovimentacaoResource::collection(
                $query->orderByDesc('data_movimentacao')->get()
            )
        );
    }

    public function store(Request $request, Produto $produto)
    {
        $validated = $request->validate([
            'id_deposito_origem'  => 'nullable|exists:depositos,id',
            'id_deposito_destino' => 'nullable|exists:depositos,id',
            'tipo'                => 'required|string|max:50',
            'quantidade'          => 'required|integer',
            'observacao'          => 'nullable|string',
            'data_movimentacao'   => 'nullable|date',
        ]);

        // Em vez de associar à variação, associamos ao produto.
        $validated['id_produto'] = $produto->id;
        $movimentacao = EstoqueMovimentacao::create($validated);
        return response()->json($movimentacao, 201);
    }

    public function show(Produto $produto, EstoqueMovimentacao $movimentacao)
    {
        if ($movimentacao->id_produto !== $produto->id) {
            return response()->json(['error' => 'Movimentação não pertence a este produto'], 404);
        }
        return response()->json($movimentacao);
    }

    public function update(Request $request, Produto $produto, EstoqueMovimentacao $movimentacao)
    {
        if ($movimentacao->id_produto !== $produto->id) {
            return response()->json(['error' => 'Movimentação não pertence a este produto'], 404);
        }

        $validated = $request->validate([
            'id_deposito_origem'  => 'nullable|exists:depositos,id',
            'id_deposito_destino' => 'nullable|exists:depositos,id',
            'tipo'                => 'sometimes|required|string|max:50',
            'quantidade'          => 'sometimes|required|integer',
            'observacao'          => 'nullable|string',
            'data_movimentacao'   => 'nullable|date',
        ]);

        $movimentacao->update($validated);
        return response()->json($movimentacao);
    }

    public function destroy(Produto $produto, EstoqueMovimentacao $movimentacao)
    {
        if ($movimentacao->id_produto !== $produto->id) {
            return response()->json(['error' => 'Movimentação não pertence a este produto'], 404);
        }

        $movimentacao->delete();
        return response()->json(null, 204);
    }
}
