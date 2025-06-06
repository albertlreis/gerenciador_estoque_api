<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstoqueMovimentacao extends Model
{
    protected $table = 'estoque_movimentacoes';

    protected $fillable = [
        'id_variacao',
        'id_deposito_origem',
        'id_deposito_destino',
        'tipo',
        'quantidade',
        'observacao',
        'data_movimentacao'
    ];

    protected $casts = [
        'data_movimentacao' => 'datetime',
    ];

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao');
    }

    public function depositoOrigem(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'id_deposito_origem');
    }

    public function depositoDestino(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'id_deposito_destino');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }
}
