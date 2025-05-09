<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Representa uma variação específica de um produto,
 * com preço, SKU e código de barras próprios.
 */
class ProdutoVariacao extends Model
{
    protected $table = 'produto_variacoes';

    protected $fillable = [
        'id_produto', 'sku', 'nome', 'preco', 'custo', 'codigo_barras'
    ];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'id_produto');
    }

    public function atributos(): HasMany
    {
        return $this->hasMany(ProdutoVariacaoAtributo::class, 'id_variacao');
    }
}
