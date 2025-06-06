<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model PedidoItem
 *
 * Representa um item dentro de um pedido, com vínculo a uma variação de produto.
 */
class PedidoItem extends Model
{
    protected $table = 'pedido_itens';

    protected $fillable = [
        'id_pedido',
        'id_variacao',
        'quantidade',
        'preco_unitario',
        'subtotal',
        'observacoes'
    ];

    protected $casts = [
        'preco_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'id_pedido');
    }

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao');
    }
}
