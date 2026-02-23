<?php

namespace App\Services;

use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoImagem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProdutoVariacaoImagemService
{
    private const DISK = 'public';

    /**
     * @return array{0:ProdutoVariacaoImagem,1:bool}
     *
     * @throws Throwable
     */
    public function upsertByUpload(ProdutoVariacao $variacao, UploadedFile $imagem): array
    {
        $disk = Storage::disk(self::DISK);
        $diretorio = "produto-variacoes/{$variacao->id}/imagem";
        $novaPath = $disk->putFile($diretorio, $imagem);
        $novaUrl = $disk->url($novaPath);

        $imagemAtual = $variacao->imagem()->first();
        $pathAntigo = $this->resolverStoragePath($imagemAtual?->url);

        try {
            $registro = DB::transaction(function () use ($variacao, $novaUrl) {
                return ProdutoVariacaoImagem::query()->updateOrCreate(
                    ['id_variacao' => $variacao->id],
                    ['url' => $novaUrl]
                );
            });
        } catch (Throwable $e) {
            $disk->delete($novaPath);
            throw $e;
        }

        if ($pathAntigo && $pathAntigo !== $novaPath) {
            $disk->delete($pathAntigo);
        }

        return [$registro->fresh(), $registro->wasRecentlyCreated];
    }

    public function remover(ProdutoVariacao $variacao): bool
    {
        $imagem = $variacao->imagem()->first();
        if (!$imagem) {
            return false;
        }

        $path = $this->resolverStoragePath($imagem->url);

        DB::transaction(function () use ($imagem) {
            $imagem->delete();
        });

        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }

        return true;
    }

    private function resolverStoragePath(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $raw = trim($url);
        if ($raw === '') {
            return null;
        }

        $path = parse_url($raw, PHP_URL_PATH) ?: $raw;
        $path = ltrim((string) $path, '/');

        if (str_starts_with($path, 'storage/')) {
            return substr($path, strlen('storage/'));
        }

        if (str_starts_with($path, 'produto-variacoes/')) {
            return $path;
        }

        $storagePos = strpos($path, '/storage/');
        if ($storagePos !== false) {
            return substr($path, $storagePos + strlen('/storage/'));
        }

        return null;
    }
}

