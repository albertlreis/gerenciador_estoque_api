<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    protected $fillable = [
        'nome',
        'nome_fantasia',
        'documento',
        'inscricao_estadual',
        'email',
        'telefone',
        'endereco',
        'tipo',
        'whatsapp',
        'cep',
        'complemento'
    ];


    public function pedidos()
    {
        return $this->hasMany(Pedido::class, 'id_cliente');
    }
}
