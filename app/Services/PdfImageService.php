<?php

namespace App\Services;

use App\Models\ProdutoImagem;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PdfImageService
{
    private const CATALOG_CARD_WIDTH = 640;

    private const CATALOG_CARD_HEIGHT = 360;

    private const CATALOG_CARD_JPEG_QUALITY = 85;

    private ?string $placeholderDataUri = null;

    /** @var array<string, string|null> */
    private array $remoteDataUris = [];

    /** @var array<string, string|null> */
    private array $catalogCardDataUris = [];

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
        if ($relativePath !== null && Storage::disk('public')->exists($relativePath)) {
            $absolutePath = Storage::disk('public')->path($relativePath);
            if (is_file($absolutePath)) {
                $cacheKey = 'pdf-image:v1:' . hash('sha256', implode('|', [
                    $absolutePath,
                    (string) (@filesize($absolutePath) ?: 0),
                    (string) (@filemtime($absolutePath) ?: 0),
                ]));
                $dataUri = Cache::remember($cacheKey, now()->addDay(), function () use ($absolutePath) {
                    $raw = @file_get_contents($absolutePath);
                    return $raw === false ? null : $this->dataUriFromBytes($raw, File::mimeType($absolutePath) ?: null);
                });
                if (is_string($dataUri) && $dataUri !== '') {
                    return $dataUri;
                }
            }
        }

        return Str::startsWith(Str::lower($normalized), 'https://')
            ? $this->remoteDataUri($normalized)
            : null;
    }

    private function dataUriFromBytes(string $raw, ?string $declaredMime = null): ?string
    {
        if ($raw === '') {
            return null;
        }

        $detectedMime = class_exists(\finfo::class)
            ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw)
            : $declaredMime;
        $allowedMimes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        if (!in_array($detectedMime, $allowedMimes, true)) {
            return null;
        }

        if ($detectedMime === 'image/webp') {
            if (!function_exists('imagecreatefromstring')) {
                return null;
            }

            $image = @imagecreatefromstring($raw);
            if ($image === false) {
                return null;
            }

            ob_start();
            $encoded = @imagepng($image);
            $png = ob_get_clean();
            imagedestroy($image);
            if (!$encoded || !is_string($png) || $png === '') {
                return null;
            }

            $raw = $png;
            $detectedMime = 'image/png';
        }

        return sprintf('data:%s;base64,%s', $detectedMime, base64_encode($raw));
    }

    private function remoteDataUri(string $url): ?string
    {
        if (array_key_exists($url, $this->remoteDataUris)) {
            return $this->remoteDataUris[$url];
        }

        $parts = parse_url($url);
        $host = Str::lower((string) ($parts['host'] ?? ''));
        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (
            $scheme !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || ($port !== null && $port !== 443)
            || !in_array($host, $this->allowedRemoteHosts(), true)
        ) {
            return $this->remoteDataUris[$url] = null;
        }

        $maxBytes = max(1, (int) config('services.pdf_images.max_bytes', 8 * 1024 * 1024));
        $timeout = max(1, (int) config('services.pdf_images.timeout_seconds', 5));

        try {
            $response = Http::timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->accept('image/*')
                ->get($url);
            if (!$response->successful()) {
                return $this->remoteDataUris[$url] = null;
            }

            $declaredMime = Str::lower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            if (!in_array($declaredMime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                return $this->remoteDataUris[$url] = null;
            }

            $declaredLength = (int) ($response->header('Content-Length') ?: 0);
            if ($declaredLength > $maxBytes) {
                return $this->remoteDataUris[$url] = null;
            }

            $raw = $response->body();
            if ($raw === '' || strlen($raw) > $maxBytes) {
                return $this->remoteDataUris[$url] = null;
            }

            return $this->remoteDataUris[$url] = $this->dataUriFromBytes($raw, $declaredMime);
        } catch (Throwable) {
            return $this->remoteDataUris[$url] = null;
        }
    }

    /** @return list<string> */
    private function allowedRemoteHosts(): array
    {
        $configured = config('services.pdf_images.allowed_hosts', []);
        $hosts = is_array($configured) ? $configured : explode(',', (string) $configured);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $hosts[] = $appHost;
        }

        return collect($hosts)
            ->map(fn ($host) => Str::lower(trim((string) $host)))
            ->filter()
            ->unique()
            ->values()
            ->all();
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

    public function fromProdutoVariacaoProdutoFirst(?ProdutoVariacao $variacao): ?string
    {
        if ($variacao === null) {
            return null;
        }

        foreach ($this->produtoFirstImagePaths($variacao) as $path) {
            $dataUri = $this->toDataUri($path);
            if ($dataUri !== null) {
                return $dataUri;
            }
        }

        return null;
    }

    public function fromProdutoVariacaoProdutoFirstOrPlaceholder(?ProdutoVariacao $variacao): string
    {
        return $this->fromProdutoVariacaoProdutoFirst($variacao) ?? $this->placeholderDataUri();
    }

    public function fromProdutoVariacaoProdutoFirstForCatalogOrPlaceholder(?ProdutoVariacao $variacao): string
    {
        $source = $this->fromProdutoVariacaoProdutoFirst($variacao);
        if ($source === null) {
            return $this->placeholderDataUri();
        }

        return $this->catalogCardDataUri($source) ?? $this->placeholderDataUri();
    }

    /**
     * Normaliza uma imagem raster para o enquadramento 16:9 usado no catálogo.
     */
    public function catalogCardDataUri(?string $dataUri): ?string
    {
        if ($dataUri === null || !function_exists('imagecreatefromstring')) {
            return null;
        }

        $cacheKey = hash('sha256', $dataUri);
        if (array_key_exists($cacheKey, $this->catalogCardDataUris)) {
            return $this->catalogCardDataUris[$cacheKey];
        }

        $facadeApp = Facade::getFacadeApplication();
        $cache = $facadeApp !== null && $facadeApp->bound('cache') ? Cache::getFacadeRoot() : null;
        $persistent = $cache?->get('pdf-catalog-card:v1:' . $cacheKey);
        if (is_string($persistent) && $persistent !== '') {
            return $this->catalogCardDataUris[$cacheKey] = $persistent;
        }

        if (!preg_match('#^data:image/(?:png|jpeg|gif|webp);base64,(.+)$#s', $dataUri, $matches)) {
            return $this->catalogCardDataUris[$cacheKey] = null;
        }

        $raw = base64_decode($matches[1], true);
        if (!is_string($raw) || $raw === '') {
            return $this->catalogCardDataUris[$cacheKey] = null;
        }

        $source = null;
        $target = null;

        try {
            $source = @imagecreatefromstring($raw);
            if ($source === false) {
                return $this->catalogCardDataUris[$cacheKey] = null;
            }

            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            if ($sourceWidth < 1 || $sourceHeight < 1 || ($sourceWidth * $sourceHeight) > 40_000_000) {
                return $this->catalogCardDataUris[$cacheKey] = null;
            }

            $targetRatio = self::CATALOG_CARD_WIDTH / self::CATALOG_CARD_HEIGHT;
            $sourceRatio = $sourceWidth / $sourceHeight;
            $cropX = 0;
            $cropY = 0;
            $cropWidth = $sourceWidth;
            $cropHeight = $sourceHeight;

            if ($sourceRatio > $targetRatio) {
                $cropWidth = max(1, (int) round($sourceHeight * $targetRatio));
                $cropX = max(0, (int) floor(($sourceWidth - $cropWidth) / 2));
            } elseif ($sourceRatio < $targetRatio) {
                $cropHeight = max(1, (int) round($sourceWidth / $targetRatio));
                $cropY = max(0, (int) floor(($sourceHeight - $cropHeight) / 2));
            }

            $target = imagecreatetruecolor(self::CATALOG_CARD_WIDTH, self::CATALOG_CARD_HEIGHT);
            if ($target === false) {
                return $this->catalogCardDataUris[$cacheKey] = null;
            }

            $background = imagecolorallocate($target, 246, 241, 232);
            imagefill($target, 0, 0, $background);
            $copied = imagecopyresampled(
                $target,
                $source,
                0,
                0,
                $cropX,
                $cropY,
                self::CATALOG_CARD_WIDTH,
                self::CATALOG_CARD_HEIGHT,
                $cropWidth,
                $cropHeight,
            );
            if (!$copied) {
                return $this->catalogCardDataUris[$cacheKey] = null;
            }

            ob_start();
            $encoded = imagejpeg($target, null, self::CATALOG_CARD_JPEG_QUALITY);
            $jpeg = ob_get_clean();
            if (!$encoded || !is_string($jpeg) || $jpeg === '') {
                return $this->catalogCardDataUris[$cacheKey] = null;
            }

            $normalized = 'data:image/jpeg;base64,' . base64_encode($jpeg);
            $cache?->put('pdf-catalog-card:v1:' . $cacheKey, $normalized, now()->addDay());
            return $this->catalogCardDataUris[$cacheKey] = $normalized;
        } catch (Throwable) {
            return $this->catalogCardDataUris[$cacheKey] = null;
        } finally {
            if ($source !== null && $source !== false) {
                imagedestroy($source);
            }
            if ($target !== null && $target !== false) {
                imagedestroy($target);
            }
        }
    }

    public function publicUrlFromProdutoVariacaoProdutoFirst(?ProdutoVariacao $variacao): ?string
    {
        if ($variacao === null) {
            return null;
        }

        foreach ($this->produtoFirstImagePaths($variacao) as $path) {
            if ($this->toDataUri($path) !== null) {
                return $this->toPublicUrl($path);
            }
        }

        return null;
    }

    public function toPublicUrl(?string $path): ?string
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

        return ProdutoImagem::normalizarUrlPublica($relativePath);
    }

    public function fromProdutoDaVariacao(?ProdutoVariacao $variacao): ?string
    {
        return $this->fromProduto($variacao?->produto);
    }

    public function fromProdutoDaVariacaoOrPlaceholder(?ProdutoVariacao $variacao): string
    {
        return $this->fromProdutoDaVariacao($variacao) ?? $this->placeholderDataUri();
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
     * @return list<string>
     */
    private function produtoFirstImagePaths(ProdutoVariacao $variacao): array
    {
        $produto = $variacao->produto;
        $produtoImagens = $produto?->relationLoaded('imagens') ? $produto->imagens : collect();
        $variacaoImagens = $variacao->relationLoaded('imagens') ? $variacao->imagens : collect();

        return collect($produtoImagens)
            ->flatMap(fn ($imagem) => [$imagem->url, $imagem->url_completa])
            ->concat([
                $produto?->imagemPrincipal?->url,
                $produto?->imagemPrincipal?->url_completa,
            ])
            ->concat(collect($variacaoImagens)
                ->flatMap(fn ($imagem) => [$imagem->url, $imagem->url_completa]))
            ->concat([
                $variacao->imagem?->url,
                $variacao->imagem?->url_completa,
            ])
            ->merge($this->productImagePathsBySameReference($variacao))
            ->map(fn ($path): string => trim((string) $path))
            ->filter()
            ->unique()
            ->values()
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
