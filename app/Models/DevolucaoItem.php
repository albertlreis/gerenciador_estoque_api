<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Representa um item dentro de uma devolução.
 *
 * @property int $devolucao_id
 * @property int $pedido_item_id
 * @property int $quantidade
 */
class DevolucaoItem extends Model
{
    protected $table = 'devolucao_itens';
    protected $fillable = ['devolucao_id', 'pedido_item_id', 'quantidade'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Devolucao, self>
     */
    public function devolucao(): BelongsTo
    {
        return $this->belongsTo(Devolucao::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\PedidoItem, self>
     */
    public function pedidoItem(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\TrocaItem>
     */
    public function trocaItens(): HasMany
    {
        return $this->hasMany(TrocaItem::class);
    }
}
