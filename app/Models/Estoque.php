<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Estoque extends Model
{
    protected $fillable = [
        'id_variacao',
        'id_deposito',
        'quantidade'
    ];

    // Cada registro de estoque está associado a uma variação de produto
    public function produtoVariacao()
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao');
    }

    // E a um depósito
    public function deposito()
    {
        return $this->belongsTo(Deposito::class, 'id_deposito');
    }
}
