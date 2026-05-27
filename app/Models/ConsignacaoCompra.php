<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsignacaoCompra extends Model
{
    protected $table = 'consignacao_compras';

    protected $fillable = [
        'consignacao_id',
        'usuario_id',
        'quantidade',
        'observacoes',
        'data_compra',
        'cancelada_em',
        'cancelada_por',
        'motivo_cancelamento',
    ];

    protected $casts = [
        'data_compra' => 'datetime',
        'cancelada_em' => 'datetime',
    ];

    public function consignacao(): BelongsTo
    {
        return $this->belongsTo(Consignacao::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function canceladaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cancelada_por');
    }
}
