<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'id_carrinho_item',
        'id_variacao',
        'quantidade',
        'preco_original',
        'preco_unitario',
        'custo_unitario',
        'subtotal',
        'observacoes',
        'id_deposito',
        'outlet_id',
        'outlet_pagamento_id',
        'outlet_preco_base',
        'outlet_forma_pagamento_id',
        'outlet_forma_pagamento',
        'outlet_percentual_desconto',
        'outlet_max_parcelas',
        'outlet_preco_final',
    ];

    protected $casts = [
        'preco_original' => 'decimal:2',
        'preco_unitario' => 'decimal:2',
        'custo_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'outlet_preco_base' => 'decimal:2',
        'outlet_percentual_desconto' => 'decimal:2',
        'outlet_preco_final' => 'decimal:2',
        'entrega_pendente' => 'boolean',
        'data_liberacao_entrega' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PedidoItem $item) {
            if ($item->preco_original === null && $item->preco_unitario !== null) {
                $item->preco_original = $item->preco_unitario;
            }
        });
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'id_pedido');
    }

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao');
    }

    public function entregaItem(): HasOne
    {
        return $this->hasOne(ProdutoEntregaItem::class, 'pedido_item_id');
    }

    public function historicoStatus(): HasMany
    {
        return $this->hasMany(PedidoItemStatusHistorico::class);
    }

    /**
     * Retorna se o item está com entrega pendente (sem liberação).
     */
    public function getIsEntregaPendenteAttribute(): bool
    {
        return $this->entrega_pendente && is_null($this->data_liberacao_entrega);
    }
}
