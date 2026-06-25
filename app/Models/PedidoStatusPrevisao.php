<?php

namespace App\Models;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoStatusPrevisao extends Model
{
    protected $table = 'pedido_status_previsoes';

    protected $fillable = [
        'pedido_id',
        'status',
        'data_prevista',
        'usuario_id',
    ];

    protected $casts = [
        'data_prevista' => 'date',
    ];

    public function setStatusAttribute(mixed $value): void
    {
        $this->attributes['status'] = $value instanceof BackedEnum ? $value->value : $value;
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(AcessoUsuario::class, 'usuario_id');
    }
}
