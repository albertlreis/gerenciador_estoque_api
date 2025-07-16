<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoFabricaItem extends Model
{
    protected $table = 'pedidos_fabrica_itens';

    protected $fillable = [
        'pedido_fabrica_id',
        'produto_variacao_id',
        'quantidade',
        'deposito_id',
        'pedido_venda_id',
        'observacoes',
    ];

    public function pedidoFabrica(): BelongsTo
    {
        return $this->belongsTo(PedidoFabrica::class, 'pedido_fabrica_id');
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class);
    }

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'produto_variacao_id');
    }

    public function pedidoVenda(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_venda_id');
    }
}
