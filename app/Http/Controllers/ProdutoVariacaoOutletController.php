<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProdutoVariacaoOutletRequest;
use App\Http\Resources\ProdutoVariacaoOutletResource;
use App\Models\OutletFormaPagamento;
use App\Models\OutletMotivo;
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
        $variacao = ProdutoVariacao::with([
            'produto',
            'outlets.usuario',
            'outlets.motivo',
            'outlets.formasPagamento.formaPagamento',
        ])->findOrFail($id);

        return response()->json([
            'variacao_id' => $variacao->id,
            'produto' => optional($variacao->produto)->nome,
            'outlets' => ProdutoVariacaoOutletResource::collection($variacao->outlets),
        ]);
    }

    public function store(StoreProdutoVariacaoOutletRequest $request, int $id): JsonResponse
    {
        $variacao = ProdutoVariacao::with(['estoques', 'outlets'])->findOrFail($id);

        $estoqueTotal = (int) $variacao->estoques->sum('quantidade');
        $totalOutletJaRegistrado = (int)$variacao->outlets->sum('quantidade');
        $quantidadeNova = (int)$request->quantidade;

        $maxDisponivel = max(0, $estoqueTotal - $totalOutletJaRegistrado);

        if ($quantidadeNova < 1 || $quantidadeNova > $maxDisponivel){
            return response()->json([
                'message' => "Quantidade inválida. Disponível para outlet: $maxDisponivel (Estoque $estoqueTotal − Outlets $totalOutletJaRegistrado)."
            ], 422);
        }

        $existeSimilar = $variacao->outlets->first(function ($outlet) use ($request) {
            return $outlet->motivo_id === $request->motivo_id &&
                $outlet->percentual_desconto == $request->percentual_desconto &&
                $outlet->quantidade == $request->quantidade;
        });

        if ($existeSimilar) {
            return response()->json([
                'message' => 'Já existe um registro outlet semelhante.'
            ], 422);
        }

        $outlet = new ProdutoVariacaoOutlet([
            'motivo_id' => $request->motivo_id,
            'quantidade' => $quantidadeNova,
            'quantidade_restante' => $quantidadeNova,
            'usuario_id' => Auth::id(),
        ]);

        $variacao->outlets()->save($outlet);

        foreach ($request->formas_pagamento as $fp) {
            $formaId = $fp['forma_pagamento_id'] ?? null;
            if (!$formaId && !empty($fp['forma_pagamento'])) {
                $formaId = OutletFormaPagamento::where('slug',$fp['forma_pagamento'])->value('id');
            }

            $outlet->formasPagamento()->create([
                'forma_pagamento_id' => $formaId,
                'percentual_desconto' => $fp['percentual_desconto'],
                'max_parcelas' => $fp['max_parcelas'] ?? null,
            ]);
        }

        $outlet->load(['usuario','motivo','formasPagamento.formaPagamento']);

        return (new ProdutoVariacaoOutletResource($outlet))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, int $id, int $outletId): ProdutoVariacaoOutletResource|JsonResponse
    {
        $variacao = ProdutoVariacao::with(['estoques', 'outlets'])->findOrFail($id);

        $estoqueTotal = (int) $variacao->estoques->sum('quantidade');
        $totalOutros = (int)$variacao->outlets->where('id','!=',$outletId)->sum('quantidade');
        $novaQtd = (int)$request->input('quantidade', 0);

        if ($novaQtd < 1 || ($totalOutros + $novaQtd) > $estoqueTotal) {
            $maxDisponivel = max(0, $estoqueTotal - $totalOutros);
            return response()->json([
                'message' => "A nova quantidade excede o disponível. Máximo permitido: {$maxDisponivel}."
            ], 422);
        }

        /** @var ProdutoVariacaoOutlet $outlet */
        $outlet = ProdutoVariacaoOutlet::where('produto_variacao_id',$id)->findOrFail($outletId);

        $data = $request->validate([
            'quantidade' => 'required|integer|min:1',
            'motivo_id'  => 'required|exists:outlet_motivos,id',
            'formas_pagamento' => 'sometimes|array|min:1',
            'formas_pagamento.*.forma_pagamento_id' => 'required_with:formas_pagamento|exists:outlet_formas_pagamento,id',
            'formas_pagamento.*.percentual_desconto'=> 'required_with:formas_pagamento|numeric|min:0|max:100',
            'formas_pagamento.*.max_parcelas'       => 'nullable|integer|min:1|max:36',
        ]);

        $outlet->update([
            'quantidade' => $data['quantidade'],
            'motivo_id'  => (int)$data['motivo_id'],
        ]);

        if ($request->has('formas_pagamento')) {
            $outlet->formasPagamento()->delete();
            foreach ($data['formas_pagamento'] as $fp) {
                $outlet->formasPagamento()->create([
                    'forma_pagamento_id' => (int)$fp['forma_pagamento_id'],
                    'percentual_desconto'=> $fp['percentual_desconto'],
                    'max_parcelas'       => $fp['max_parcelas'] ?? null,
                ]);
            }
        }

        $outlet->load(['usuario','motivo','formasPagamento.formaPagamento']);
        return new ProdutoVariacaoOutletResource($outlet);
    }

    public function destroy(int $id, int $outletId): JsonResponse
    {
        $outlet = ProdutoVariacaoOutlet::where('produto_variacao_id', $id)->findOrFail($outletId);
        $outlet->delete();

        return response()->json(['message' => 'Outlet removido com sucesso']);
    }

}
