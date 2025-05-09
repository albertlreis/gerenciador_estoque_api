<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model CarrinhoItem
 *
 * Representa um item dentro de um carrinho temporário.
 */
class CarrinhoItem extends Model
{
    protected $table = 'carrinho_itens';

    protected $fillable = [
        'id_carrinho',
        'id_variacao',
        'quantidade',
        'preco_unitario',
        'subtotal',
    ];

    protected $casts = [
        'preco_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function carrinho(): BelongsTo
    {
        return $this->belongsTo(Carrinho::class, 'id_carrinho');
    }

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao');
    }
}
