<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProdutoVariacaoImagemUpsertRequest;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoImagem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
            return $this->upsertImagem($request, $variacao);
        });

        $status = $imagem->wasRecentlyCreated ? 201 : 200;

        return response()->json($this->toPayload($imagem), $status);
    }

    public function update(ProdutoVariacaoImagemUpsertRequest $request, ProdutoVariacao $variacao): JsonResponse
    {
        $imagem = DB::transaction(function () use ($request, $variacao) {
            return $this->upsertImagem($request, $variacao);
        });

        return response()->json($this->toPayload($imagem), 200);
    }

    public function destroy(ProdutoVariacao $variacao): JsonResponse
    {
        $imagem = $variacao->imagem()->first();

        if (!$imagem) {
            return response()->json(['message' => 'Imagem da variação não encontrada.'], 404);
        }

        // tenta remover arquivo
        $this->deleteFileByUrl($imagem->url);

        $imagem->delete();

        return response()->json(null, 204);
    }

    private function upsertImagem(ProdutoVariacaoImagemUpsertRequest $request, ProdutoVariacao $variacao): ProdutoVariacaoImagem
    {
        $existing = $variacao->imagem()->first();
        $oldUrl = $existing?->url;

        $file = $request->file('imagem');
        $path = $file->store('produtos/variacoes', 'public'); // -> /storage/produtos/variacoes/...
        $url = Storage::disk('public')->url($path);

        $imagem = ProdutoVariacaoImagem::query()->updateOrCreate(
            ['id_variacao' => $variacao->id],
            ['url' => $url]
        );

        // remove arquivo anterior se mudou
        if ($oldUrl && $oldUrl !== $url) {
            $this->deleteFileByUrl($oldUrl);
        }

        return $imagem;
    }

    private function deleteFileByUrl(?string $url): void
    {
        if (!$url) return;

        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        // casos comuns:
        // - /storage/produtos/variacoes/abc.jpg
        // - storage/produtos/variacoes/abc.jpg
        $path = Str::start($path, '/');

        $relative = null;
        if (Str::startsWith($path, '/storage/')) {
            $relative = ltrim(Str::after($path, '/storage/'), '/'); // vira produtos/...
        } else {
            $relative = ltrim($path, '/'); // tenta como relativo
        }

        if ($relative) {
            Storage::disk('public')->delete($relative);
        }
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
