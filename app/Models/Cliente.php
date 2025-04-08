<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    protected $fillable = [
        'nome',
        'documento',
        'email',
        'telefone',
        'endereco'
    ];

    // Um cliente pode realizar vários pedidos
    public function pedidos()
    {
        return $this->hasMany(Pedido::class, 'id_cliente');
    }
}
