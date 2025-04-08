<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deposito extends Model
{
    protected $fillable = [
        'nome',
        'endereco'
    ];

    // Um depósito possui muitos registros de estoque
    public function estoque()
    {
        return $this->hasMany(Estoque::class, 'id_deposito');
    }

    // Movimentações onde o depósito é a origem
    public function movimentacoesOrigem()
    {
        return $this->hasMany(EstoqueMovimentacao::class, 'id_deposito_origem');
    }

    // Movimentações onde o depósito é o destino
    public function movimentacoesDestino()
    {
        return $this->hasMany(EstoqueMovimentacao::class, 'id_deposito_destino');
    }
}
