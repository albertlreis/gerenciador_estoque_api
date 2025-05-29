<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Representa uma variação específica de um produto,
 * com preço, referencia e código de barras próprios.
 */
class ProdutoVariacao extends Model
{
    protected $table = 'produto_variacoes';

    protected $fillable = [
        'produto_id', 'referencia', 'nome', 'preco', 'custo', 'codigo_barras'
    ];

    protected $appends = ['nome_completo', 'estoque_total'];

    protected $with = ['produto'];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }

    public function atributos(): HasMany
    {
        return $this->hasMany(ProdutoVariacaoAtributo::class, 'id_variacao');
    }

    public function estoque(): HasOne
    {
        return $this->hasOne(Estoque::class, 'id_variacao');
    }

    public function getEstoqueTotalAttribute(): int
    {
        return $this->estoque->quantidade ?? 0;
    }

    public function getNomeCompletoAttribute(): string
    {
        $produto = $this->produto->nome ?? '';

        $atributos = $this->atributos()
            ->get()
            ->map(fn($attr) => "{$attr->atributo}: {$attr->valor}")
            ->implode(' - ');

        $complemento = trim($atributos ?: '');

        return trim("$produto - $complemento") ?: '-';
    }
}
