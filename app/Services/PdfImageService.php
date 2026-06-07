<?php

namespace App\Services;

use App\Models\ProdutoImagem;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PdfImageService
{
    private ?string $placeholderDataUri = null;

    /**
     * @var array<string, list<array{produto_id: int, url: string}>>
     */
    private array $productImagesByReference = [];

    /**
     * Converte uma imagem (path relativo/public URL) para data-uri.
     */
    public function toDataUri(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $normalized = trim($path);
        if ($normalized === '') {
            return null;
        }

        $relativePath = $this->normalizeToStorageRelativePath($normalized);
        if ($relativePath === null) {
            return null;
        }

        if (!Storage::disk('public')->exists($relativePath)) {
            return null;
        }

        $absolutePath = Storage::disk('public')->path($relativePath);
        if (!is_file($absolutePath)) {
            return null;
        }

        $raw = @file_get_contents($absolutePath);
        if ($raw === false) {
            return null;
        }

        $mime = File::mimeType($absolutePath) ?: 'application/octet-stream';
        return sprintf('data:%s;base64,%s', $mime, base64_encode($raw));
    }

    public function toPdfSrc(?string $path): string
    {
        return $this->toDataUri($path) ?? $this->placeholderDataUri();
    }

    public function placeholderDataUri(): string
    {
        if ($this->placeholderDataUri !== null) {
            return $this->placeholderDataUri;
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="64" viewBox="0 0 80 64">'
            . '<rect width="80" height="64" fill="#f1f5f9"/>'
            . '<rect x="0.5" y="0.5" width="79" height="63" fill="none" stroke="#cbd5e1"/>'
            . '<path d="M20 42l12-14 9 10 6-7 13 11H20z" fill="#cbd5e1"/>'
            . '<circle cx="56" cy="20" r="6" fill="#cbd5e1"/>'
            . '<text x="40" y="55" text-anchor="middle" font-family="Arial, sans-serif" font-size="8" fill="#64748b">SEM IMG</text>'
            . '</svg>';

        return $this->placeholderDataUri = 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function fromProdutoVariacao(?ProdutoVariacao $variacao): ?string
    {
        if ($variacao === null) {
            return null;
        }

        $paths = [
            $variacao->imagem?->url,
            $variacao->produto?->imagemPrincipal?->url,
            $variacao->produto?->imagemPrincipal?->url_completa,
        ];

        foreach ($paths as $path) {
            $dataUri = $this->toDataUri($path);
            if ($dataUri !== null) {
                return $dataUri;
            }
        }

        foreach ($this->productImagePathsBySameReference($variacao) as $path) {
            $dataUri = $this->toDataUri($path);
            if ($dataUri !== null) {
                return $dataUri;
            }
        }

        return null;
    }

    public function fromProdutoVariacaoOrPlaceholder(?ProdutoVariacao $variacao): string
    {
        return $this->fromProdutoVariacao($variacao) ?? $this->placeholderDataUri();
    }

    public function fromProduto(?Produto $produto): ?string
    {
        if ($produto === null) {
            return null;
        }

        $paths = [
            $produto->imagemPrincipal?->url,
            $produto->imagemPrincipal?->url_completa,
        ];

        foreach ($paths as $path) {
            $dataUri = $this->toDataUri($path);
            if ($dataUri !== null) {
                return $dataUri;
            }
        }

        return null;
    }

    public function fromProdutoOrPlaceholder(?Produto $produto): string
    {
        return $this->fromProduto($produto) ?? $this->placeholderDataUri();
    }

    /**
     * @return list<string>
     */
    private function productImagePathsBySameReference(ProdutoVariacao $variacao): array
    {
        $referencia = trim((string) $variacao->referencia);
        if ($referencia === '') {
            return [];
        }

        $produtoId = (int) ($variacao->produto_id ?? $variacao->produto?->id ?? 0);

        return collect($this->cachedProductImagesByReference($referencia))
            ->reject(fn (array $image): bool => $produtoId > 0 && $image['produto_id'] === $produtoId)
            ->pluck('url')
            ->all();
    }

    /**
     * @return list<array{produto_id: int, url: string}>
     */
    private function cachedProductImagesByReference(string $referencia): array
    {
        if (array_key_exists($referencia, $this->productImagesByReference)) {
            return $this->productImagesByReference[$referencia];
        }

        $images = DB::table('produto_imagens as pi')
            ->join('produtos as p', 'p.id', '=', 'pi.id_produto')
            ->join('produto_variacoes as pv', 'pv.produto_id', '=', 'p.id')
            ->where('pv.referencia', $referencia)
            ->whereNotNull('pi.url')
            ->whereRaw("TRIM(pi.url) <> ''")
            ->select([
                'pi.id as imagem_id',
                'pi.id_produto as produto_id',
                'pi.url',
                'pi.principal',
                'p.ativo',
            ])
            ->distinct()
            ->orderByDesc('p.ativo')
            ->orderByDesc('pi.principal')
            ->orderBy('pi.id')
            ->get()
            ->map(fn ($image): array => [
                'produto_id' => (int) $image->produto_id,
                'url' => trim((string) $image->url),
            ])
            ->all();

        return $this->productImagesByReference[$referencia] = $images;
    }

    private function normalizeToStorageRelativePath(string $path): ?string
    {
        if (Str::startsWith($path, ['http://', 'https://'])) {
            $urlPath = (string) parse_url($path, PHP_URL_PATH);
            if ($urlPath === '') {
                return null;
            }
            $path = $urlPath;
        }

        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = ltrim($path, '/');

        // Caminhos absolutos comuns em container/produção:
        // - /var/www/html/public/storage/produtos/...
        // - /var/www/html/storage/app/public/produtos/...
        if (str_contains($path, '/public/storage/')) {
            $path = (string) Str::after($path, '/public/storage/');
        } elseif (str_contains($path, '/storage/app/public/')) {
            $path = (string) Str::after($path, '/storage/app/public/');
        }

        if (Str::startsWith($path, 'storage/')) {
            return ltrim(substr($path, strlen('storage/')), '/');
        }

        if (Str::startsWith($path, 'app/public/')) {
            return ltrim(substr($path, strlen('app/public/')), '/');
        }

        if (Str::startsWith($path, 'public/storage/')) {
            return ltrim(substr($path, strlen('public/storage/')), '/');
        }

        if (Str::startsWith($path, 'uploads/produtos/')) {
            return ltrim(substr($path, strlen('uploads/')), '/');
        }

        if (!str_contains($path, '/') && $path !== '') {
            return ProdutoImagem::FOLDER . '/' . $path;
        }

        return $path;
    }
}
