<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Representa uma variação nova trocada em uma devolução.
 *
 * @property int $devolucao_item_id
 * @property int $nova_variacao_id
 * @property int $quantidade
 * @property float $preco_unitario
 */
class TrocaItem extends Model
{
    protected $table = 'troca_itens';
    protected $fillable = ['devolucao_item_id', 'nova_variacao_id', 'quantidade', 'preco_unitario'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\DevolucaoItem, self>
     */
    public function devolucaoItem(): BelongsTo
    {
        return $this->belongsTo(DevolucaoItem::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\ProdutoVariacao, self>
     */
    public function variacaoNova(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'nova_variacao_id');
    }
}
