<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProdutoVariacaoOutletRequest;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoOutlet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProdutoVariacaoOutletController extends Controller
{
    /**
     * Lista todos os registros de outlet de uma variação.
     */
    public function index(int $id): JsonResponse
    {
        $variacao = ProdutoVariacao::with(['outlets.usuario'])->findOrFail($id);

        $outlets = $variacao->outlets->map(function ($outlet) {
            return [
                'id' => $outlet->id,
                'motivo' => $outlet->motivo,
                'quantidade' => $outlet->quantidade,
                'quantidade_restante' => $outlet->quantidade_restante,
                'percentual_desconto' => $outlet->percentual_desconto,
                'usuario' => $outlet->usuario?->nome ?? 'Desconhecido',
                'created_at' => $outlet->created_at?->toDateTimeString(),
                'updated_at' => $outlet->updated_at?->toDateTimeString(),
                'formas_pagamento' => $outlet->formasPagamento->map(fn($fp) => [
                    'forma_pagamento' => $fp->forma_pagamento,
                    'percentual_desconto' => $fp->percentual_desconto,
                    'max_parcelas' => $fp->max_parcelas,
                ]),
            ];
        });

        return response()->json([
            'variacao_id' => $variacao->id,
            'produto' => optional($variacao->produto)->nome,
            'outlets' => $outlets,
        ]);
    }

    public function store(StoreProdutoVariacaoOutletRequest $request, int $id): JsonResponse
    {
        $variacao = ProdutoVariacao::with(['estoque', 'outlets'])->findOrFail($id);

        $existeSimilar = $variacao->outlets->first(function ($outlet) use ($request) {
            return $outlet->motivo === $request->motivo &&
                $outlet->percentual_desconto == $request->percentual_desconto &&
                $outlet->quantidade == $request->quantidade;
        });

        if ($existeSimilar) {
            return response()->json([
                'message' => 'Já existe um registro outlet semelhante.'
            ], 422);
        }

        $estoqueTotal = $variacao->estoque->quantidade ?? 0;
        $totalOutletJaRegistrado = $variacao->outlets->sum('quantidade');

        $quantidadeNova = $request->quantidade;
        $quantidadeTotalPosInsercao = $totalOutletJaRegistrado + $quantidadeNova;

        if ($quantidadeTotalPosInsercao > $estoqueTotal) {
            return response()->json([
                'message' => "A soma das quantidades de outlet existentes ({$totalOutletJaRegistrado}) com a nova ({$quantidadeNova}) ultrapassa o estoque total ({$estoqueTotal})."
            ], 422);
        }

        $outlet = new ProdutoVariacaoOutlet([
            'motivo' => $request->motivo,
            'quantidade' => $quantidadeNova,
            'quantidade_restante' => $quantidadeNova,
            'usuario_id' => Auth::id(),
        ]);

        $variacao->outlets()->save($outlet);

        foreach ($request->formas_pagamento as $fp) {
            $outlet->formasPagamento()->create([
                'forma_pagamento' => $fp['forma_pagamento'],
                'percentual_desconto' => $fp['percentual_desconto'],
                'max_parcelas' => $fp['max_parcelas'] ?? null,
            ]);
        }

        return response()->json(['message' => 'Variação registrada como outlet com sucesso.'], 201);
    }

    public function update(Request $request, int $id, int $outletId)
    {
        $variacao = ProdutoVariacao::with(['estoque', 'outlets'])->findOrFail($id);

        $estoqueTotal = $variacao->estoque->quantidade ?? 0;
        $totalOutros = $variacao->outlets->where('id', '!=', $outletId)->sum('quantidade');
        $novaQtd = $request->quantidade;

        if (($totalOutros + $novaQtd) > $estoqueTotal) {
            return response()->json([
                'message' => 'A nova quantidade excede o estoque disponível.'
            ], 422);
        }

        $outlet = ProdutoVariacaoOutlet::where('produto_variacao_id', $id)->findOrFail($outletId);

        $data = $request->validate([
            'quantidade' => 'required|integer|min:1',
            'percentual_desconto' => 'required|numeric|min:0|max:100',
            'motivo' => 'required|string|max:255',
        ]);

        $outlet->update($data);

        return response()->json($outlet);
    }

    public function destroy(int $id, int $outletId): JsonResponse
    {
        $outlet = ProdutoVariacaoOutlet::where('produto_variacao_id', $id)->findOrFail($outletId);
        $outlet->delete();

        return response()->json(['message' => 'Outlet removido com sucesso']);
    }

}
