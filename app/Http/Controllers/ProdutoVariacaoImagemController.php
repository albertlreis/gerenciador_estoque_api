<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProdutoVariacaoImagemUpsertRequest;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoImagem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProdutoVariacaoImagemController extends Controller
{
    public function show(ProdutoVariacao $variacao): JsonResponse
    {
        $imagem = $variacao->imagem()->first();

        if (!$imagem) {
            return response()->json(['message' => 'Imagem da variação não encontrada.'], 404);
        }

        return response()->json($this->toPayload($imagem));
    }

    public function store(ProdutoVariacaoImagemUpsertRequest $request, ProdutoVariacao $variacao): JsonResponse
    {
        $imagem = DB::transaction(function () use ($request, $variacao) {
            return ProdutoVariacaoImagem::query()->updateOrCreate(
                ['id_variacao' => $variacao->id],
                ['url' => $request->validated('url')]
            );
        });

        $status = $imagem->wasRecentlyCreated ? 201 : 200;

        return response()->json($this->toPayload($imagem), $status);
    }

    public function update(ProdutoVariacaoImagemUpsertRequest $request, ProdutoVariacao $variacao): JsonResponse
    {
        $imagem = DB::transaction(function () use ($request, $variacao) {
            return ProdutoVariacaoImagem::query()->updateOrCreate(
                ['id_variacao' => $variacao->id],
                ['url' => $request->validated('url')]
            );
        });

        return response()->json($this->toPayload($imagem), 200);
    }

    public function destroy(ProdutoVariacao $variacao): JsonResponse
    {
        $imagem = $variacao->imagem()->first();

        if (!$imagem) {
            return response()->json(['message' => 'Imagem da variação não encontrada.'], 404);
        }

        $imagem->delete();

        return response()->json(null, 204);
    }

    private function toPayload(ProdutoVariacaoImagem $imagem): array
    {
        return [
            'id' => $imagem->id,
            'id_variacao' => $imagem->id_variacao,
            'url' => $imagem->url,
            'created_at' => $imagem->created_at,
            'updated_at' => $imagem->updated_at,
        ];
    }
}

