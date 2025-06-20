<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProdutoVariacaoOutletRequest;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoOutlet;
use Illuminate\Http\JsonResponse;
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
            ];
        });

        return response()->json([
            'variacao_id' => $variacao->id,
            'produto' => $variacao->produto->nome ?? null,
            'outlets' => $outlets,
        ]);
    }

    public function store(StoreProdutoVariacaoOutletRequest $request, int $id): JsonResponse
    {
        $variacao = ProdutoVariacao::with(['estoque', 'outlets'])->findOrFail($id);

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
            'percentual_desconto' => $request->percentual_desconto,
            'usuario_id' => Auth::id(),
        ]);

        $variacao->outlets()->save($outlet);

        return response()->json(['message' => 'Variação registrada como outlet com sucesso.'], 201);
    }

}
