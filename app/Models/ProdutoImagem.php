<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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
    public function getUrlCompletaAttribute(): string
    {
        return Storage::disk(self::DISK)->url(self::FOLDER . '/' . $this->url);
    }
}
