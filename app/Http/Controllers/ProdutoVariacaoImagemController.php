<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProdutoVariacaoImagemUpsertRequest;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoImagem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProdutoVariacaoImagemController extends Controller
{
    public function index(ProdutoVariacao $variacao): JsonResponse
    {
        return response()->json(
            $variacao->imagens()->get()->map(fn (ProdutoVariacaoImagem $imagem) => $this->toPayload($imagem))->values()
        );
    }

    public function show(ProdutoVariacao $variacao): JsonResponse
    {
        $imagem = $this->imagemPrincipalDaVariacao($variacao);

        if (!$imagem) {
            return response()->json(['message' => 'Imagem da variacao nao encontrada.'], 404);
        }

        return response()->json($this->toPayload($imagem));
    }

    public function store(ProdutoVariacaoImagemUpsertRequest $request, ProdutoVariacao $variacao): JsonResponse
    {
        $imagem = DB::transaction(function () use ($request, $variacao) {
            return $this->criarImagem($request, $variacao);
        });

        return response()->json($this->toPayload($imagem), 201);
    }

    public function legacyStore(ProdutoVariacaoImagemUpsertRequest $request, ProdutoVariacao $variacao): JsonResponse
    {
        if (!$this->imagemPrincipalDaVariacao($variacao)) {
            return $this->store($request, $variacao);
        }

        return $this->update($request, $variacao);
    }

    public function update(ProdutoVariacaoImagemUpsertRequest $request, ProdutoVariacao $variacao): JsonResponse
    {
        $imagem = DB::transaction(function () use ($request, $variacao) {
            $imagem = $this->imagemPrincipalDaVariacao($variacao);

            if (!$imagem) {
                return $this->criarImagem($request, $variacao);
            }

            return $this->atualizarImagem($request, $variacao, $imagem);
        });

        return response()->json($this->toPayload($imagem), 200);
    }

    public function destroy(ProdutoVariacao $variacao): JsonResponse
    {
        $imagem = $this->imagemPrincipalDaVariacao($variacao);

        if (!$imagem) {
            return response()->json(['message' => 'Imagem da variacao nao encontrada.'], 404);
        }

        return $this->destroyImagem($variacao, $imagem);
    }

    public function updateImagem(Request $request, ProdutoVariacao $variacao, ProdutoVariacaoImagem $imagem): JsonResponse
    {
        $this->assertImagemDaVariacao($variacao, $imagem);

        $request->validate([
            'imagem' => 'sometimes|required|file|image|mimes:jpg,jpeg,png,webp,gif|max:5120',
            'principal' => 'sometimes|boolean',
            'ordem' => 'sometimes|integer|min:0',
        ]);

        $imagem = DB::transaction(fn () => $this->atualizarImagem($request, $variacao, $imagem));

        return response()->json($this->toPayload($imagem), 200);
    }

    public function definirPrincipal(ProdutoVariacao $variacao, ProdutoVariacaoImagem $imagem): JsonResponse
    {
        $this->assertImagemDaVariacao($variacao, $imagem);

        DB::transaction(function () use ($imagem): void {
            $this->marcarComoPrincipal($imagem);
        });

        return response()->json($this->toPayload($imagem->refresh()), 200);
    }

    public function destroyImagem(ProdutoVariacao $variacao, ProdutoVariacaoImagem $imagem): JsonResponse
    {
        $this->assertImagemDaVariacao($variacao, $imagem);

        DB::transaction(function () use ($variacao, $imagem): void {
            $eraPrincipal = (bool) $imagem->principal;

            $this->deleteFileByUrl($imagem->url);
            $imagem->delete();

            if ($eraPrincipal) {
                $this->garantirImagemPrincipal($variacao);
            }
        });

        return response()->json(null, 204);
    }

    private function criarImagem(Request $request, ProdutoVariacao $variacao): ProdutoVariacaoImagem
    {
        /** @var UploadedFile $file */
        $file = $request->file('imagem');
        $temImagens = $variacao->imagens()->exists();
        $principal = !$temImagens || ($request->has('principal') && $request->boolean('principal'));
        $ordem = $request->has('ordem')
            ? (int) $request->input('ordem')
            : ((int) $variacao->imagens()->max('ordem') + 1);

        $imagem = $variacao->imagens()->create([
            'url' => $this->storeFile($file),
            'principal' => $principal,
            'ordem' => $ordem,
        ]);

        if ($principal) {
            $this->marcarComoPrincipal($imagem);
        } else {
            $this->garantirImagemPrincipal($variacao);
        }

        return $imagem->refresh();
    }

    private function atualizarImagem(Request $request, ProdutoVariacao $variacao, ProdutoVariacaoImagem $imagem): ProdutoVariacaoImagem
    {
        $updates = [];
        $oldUrl = $imagem->url;

        if ($request->hasFile('imagem')) {
            /** @var UploadedFile $file */
            $file = $request->file('imagem');
            $updates['url'] = $this->storeFile($file);
        }

        if ($request->has('ordem')) {
            $updates['ordem'] = (int) $request->input('ordem');
        }

        if (!empty($updates)) {
            $imagem->update($updates);
        }

        if (isset($updates['url']) && $oldUrl && $oldUrl !== $updates['url']) {
            $this->deleteFileByUrl($oldUrl);
        }

        if ($request->has('principal')) {
            if ($request->boolean('principal')) {
                $this->marcarComoPrincipal($imagem);
            } else {
                $imagem->update(['principal' => false]);
                $this->garantirImagemPrincipal($variacao);
            }
        }

        return $imagem->refresh();
    }

    private function storeFile(UploadedFile $file): string
    {
        $path = $file->store('produtos/variacoes', 'public');

        return Storage::disk('public')->url($path);
    }

    private function imagemPrincipalDaVariacao(ProdutoVariacao $variacao): ?ProdutoVariacaoImagem
    {
        return $variacao->imagens()
            ->orderByDesc('principal')
            ->orderBy('ordem')
            ->orderBy('id')
            ->first();
    }

    private function marcarComoPrincipal(ProdutoVariacaoImagem $imagem): void
    {
        ProdutoVariacaoImagem::query()
            ->where('id_variacao', $imagem->id_variacao)
            ->update(['principal' => false]);

        ProdutoVariacaoImagem::query()
            ->whereKey($imagem->id)
            ->update(['principal' => true]);
    }

    private function garantirImagemPrincipal(ProdutoVariacao $variacao): void
    {
        $temPrincipal = $variacao->imagens()->where('principal', true)->exists();
        if ($temPrincipal) {
            return;
        }

        $proxima = $this->imagemPrincipalDaVariacao($variacao);
        if ($proxima) {
            $proxima->update(['principal' => true]);
        }
    }

    private function assertImagemDaVariacao(ProdutoVariacao $variacao, ProdutoVariacaoImagem $imagem): void
    {
        abort_unless((int) $imagem->id_variacao === (int) $variacao->id, 404, 'Imagem nao pertence a esta variacao.');
    }

    private function deleteFileByUrl(?string $url): void
    {
        if (!$url) {
            return;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = Str::start($path, '/');

        $relative = Str::startsWith($path, '/storage/')
            ? ltrim(Str::after($path, '/storage/'), '/')
            : ltrim($path, '/');

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
            'url_completa' => $imagem->url_completa,
            'principal' => (bool) $imagem->principal,
            'ordem' => (int) ($imagem->ordem ?? 0),
            'created_at' => $imagem->created_at,
            'updated_at' => $imagem->updated_at,
        ];
    }
}
