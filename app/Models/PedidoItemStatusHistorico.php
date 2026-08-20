<?php

namespace App\Models;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoItemStatusHistorico extends Model
{
    protected $table = 'pedido_item_status_historico';

    protected $fillable = [
        'grupo_uuid',
        'pedido_id',
        'pedido_item_id',
        'status',
        'quantidade',
        'quantidade_avancada',
        'data_status',
        'data_prevista',
        'usuario_id',
        'observacoes',
    ];

    protected $casts = [
        'quantidade' => 'integer',
        'quantidade_avancada' => 'integer',
        'data_status' => 'datetime',
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

    public function pedidoItem(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(AcessoUsuario::class, 'usuario_id');
    }
}
