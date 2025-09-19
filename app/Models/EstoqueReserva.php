<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstoqueReserva extends Model
{
    protected $table = 'estoque_reservas';

    protected $fillable = [
        'id_variacao',
        'id_deposito',
        'pedido_id',
        'quantidade',
        'motivo',
        'data_expira',
    ];

    protected $casts = [
        'data_expira' => 'datetime',
    ];
}
