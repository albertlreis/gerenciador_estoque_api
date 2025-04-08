<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PedidoItem extends Model
{
    protected $fillable = [
        'id_pedido',
        'id_variacao',
        'quantidade',
        'preco_unitario'
    ];

    // Cada item pertence a um pedido
    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'id_pedido');
    }

    // Cada item está relacionado a uma variação de produto
    public function produtoVariacao()
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao');
    }
}
