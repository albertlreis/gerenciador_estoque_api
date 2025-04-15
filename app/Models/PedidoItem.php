<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PedidoItem extends Model
{
    protected $table = 'pedido_itens';

    protected $fillable = [
        'id_pedido',
        'id_produto',
        'quantidade',
        'preco_unitario'
    ];

    // Cada item pertence a um pedido
    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'id_pedido');
    }

    // Cada item está relacionado a uma variação de produto
    public function produto()
    {
        return $this->belongsTo(Produto::class, 'id_produto');
    }
}
