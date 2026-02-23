<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProdutoVariacaoImagemUpsertRequest;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoImagem;
use App\Services\ProdutoVariacaoImagemService;
use Illuminate\Http\JsonResponse;

class ProdutoVariacaoImagemController extends Controller
{
    public function __construct(private readonly ProdutoVariacaoImagemService $service) {}

    public function show(ProdutoVariacao $variacao): JsonResponse
    {
        $imagem = $variacao->imagem()->first();

        if (!$imagem) {
            return response()->json(['message' => 'Imagem da variacao nao encontrada.'], 404);
        }

        return response()->json($this->toPayload($imagem));
    }

    public function store(ProdutoVariacaoImagemUpsertRequest $request, ProdutoVariacao $variacao): JsonResponse
    {
        [$imagem, $created] = $this->service->upsertByUpload($variacao, $request->file('imagem'));
        $status = $created ? 201 : 200;

        return response()->json($this->toPayload($imagem), $status);
    }

    public function update(ProdutoVariacaoImagemUpsertRequest $request, ProdutoVariacao $variacao): JsonResponse
    {
        [$imagem] = $this->service->upsertByUpload($variacao, $request->file('imagem'));

        return response()->json($this->toPayload($imagem), 200);
    }

    public function destroy(ProdutoVariacao $variacao): JsonResponse
    {
        if (!$this->service->remover($variacao)) {
            return response()->json(['message' => 'Imagem da variacao nao encontrada.'], 404);
        }

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
