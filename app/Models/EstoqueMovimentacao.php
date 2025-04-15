<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstoqueMovimentacao extends Model
{
    protected $table = 'estoque_movimentacoes';

    protected $fillable = [
        'id_produto',
        'id_deposito_origem',
        'id_deposito_destino',
        'tipo',
        'quantidade',
        'observacao',
        'data_movimentacao'
    ];

    public function produto()
    {
        return $this->belongsTo(Produto::class, 'id_produto');
    }

    public function depositoOrigem()
    {
        return $this->belongsTo(Deposito::class, 'id_deposito_origem');
    }

    public function depositoDestino()
    {
        return $this->belongsTo(Deposito::class, 'id_deposito_destino');
    }
}
