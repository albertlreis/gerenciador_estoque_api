<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstoqueTransferenciaItem extends Model
{
    protected $table = 'estoque_transferencia_itens';

    protected $fillable = [
        'transferencia_id',
        'id_variacao',
        'quantidade',
        'corredor',
        'prateleira',
        'nivel',
    ];

    public function transferencia(): BelongsTo
    {
        return $this->belongsTo(EstoqueTransferencia::class, 'transferencia_id');
    }

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao');
    }
}
