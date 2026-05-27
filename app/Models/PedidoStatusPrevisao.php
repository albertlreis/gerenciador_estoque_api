<?php

namespace App\Models;

use App\Enums\PedidoStatus;
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
        'status' => PedidoStatus::class,
        'data_prevista' => 'date',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(AcessoUsuario::class, 'usuario_id');
    }
}
