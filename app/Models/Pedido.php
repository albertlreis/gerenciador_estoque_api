<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pedido extends Model
{
    protected $fillable = [
        'id_cliente',
        'data_pedido',
        'status',
        'observacoes'
    ];

    // Cada pedido pertence a um cliente
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }

    // Um pedido possui vários itens
    public function itens()
    {
        return $this->hasMany(PedidoItem::class, 'id_pedido');
    }
}
