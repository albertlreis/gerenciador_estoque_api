<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstoqueMovimentacao extends Model
{
    protected $fillable = [
        'id_variacao',
        'id_deposito_origem',
        'id_deposito_destino',
        'tipo',
        'quantidade',
        'observacao',
        'data_movimentacao'
    ];

    // Cada movimentação está relacionada a uma variação de produto
    public function produtoVariacao()
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao');
    }

    // Relacionamento com o depósito de origem
    public function depositoOrigem()
    {
        return $this->belongsTo(Deposito::class, 'id_deposito_origem');
    }

    // Relacionamento com o depósito de destino
    public function depositoDestino()
    {
        return $this->belongsTo(Deposito::class, 'id_deposito_destino');
    }
}
