<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoFabrica extends Model
{
    protected $table = 'pedidos_fabrica';

    protected $fillable = [
        'status',
        'data_previsao_entrega',
        'observacoes',
    ];

    protected $casts = [
        'data_previsao_entrega' => 'date',
    ];

    public function itens(): HasMany
    {
        return $this->hasMany(PedidoFabricaItem::class, 'pedido_fabrica_id');
    }
}
