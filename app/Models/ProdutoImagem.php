<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $id_produto
 * @property string $url   Nome do arquivo (ex.: "a1b2c3.jpg")
 * @property bool $principal
 * @property-read string $url_completa
 */
class ProdutoImagem extends Model
{
    /** @var string Pasta das imagens dentro do disco public */
    public const FOLDER = 'produtos';

    /** @var string Nome do disco de armazenamento */
    public const DISK = 'public';

    protected $table = 'produto_imagens';

    protected $fillable = [
        'id_produto',
        'url',        // apenas o nome do arquivo (sem pasta)
        'principal',
    ];

    protected $casts = [
        'principal' => 'boolean',
    ];

    protected $appends = [
        'url_completa',
    ];

    /**
     * Relação: cada imagem pertence a um produto.
     *
     * @return BelongsTo<Produto, ProdutoImagem>
     */
    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'id_produto');
    }

    /**
     * URL pública completa da imagem (via Storage::url).
     * Ex.: /storage/produtos/a1b2c3.jpg
     *
     * @return string
     */
    public function getUrlCompletaAttribute(): ?string
    {
        return self::normalizarUrlPublica($this->url);
    }

    public static function normalizarUrlPublica(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        if (Str::startsWith($valor, ['http://', 'https://'])) {
            return $valor;
        }

        $path = '/' . ltrim($valor, '/');

        if (Str::startsWith($path, '/uploads/produtos/')) {
            $path = '/storage/produtos/' . ltrim(substr($path, strlen('/uploads/produtos/')), '/');
            return self::toAbsoluteUrl($path);
        }

        if (Str::startsWith($path, '/storage/')) {
            $path = preg_replace('#^/storage/(?:produtos/)+#', '/storage/produtos/', $path) ?? $path;
            if (!Str::startsWith($path, '/storage/produtos/')) {
                $path = '/storage/produtos/' . ltrim(substr($path, strlen('/storage/')), '/');
            }

            return self::toAbsoluteUrl($path);
        }

        $relative = ltrim($path, '/');
        $relative = preg_replace('#^(?:' . preg_quote(self::FOLDER, '#') . '/)+#', self::FOLDER . '/', $relative) ?? $relative;
        if (!Str::startsWith($relative, self::FOLDER . '/')) {
            $relative = self::FOLDER . '/' . $relative;
        }

        return self::toAbsoluteUrl(Storage::disk(self::DISK)->url($relative));
    }

    private static function toAbsoluteUrl(string $path): string
    {
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $path = '/' . ltrim($path, '/');
        $base = rtrim((string) config('app.url'), '/');

        return $base !== '' ? $base . $path : $path;
    }
}
