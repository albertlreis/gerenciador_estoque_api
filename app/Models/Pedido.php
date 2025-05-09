<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Pedido
 *
 * Representa um pedido realizado no sistema, associado a um cliente,
 * registrado por um usuário (vendedor) e podendo ter um parceiro (arquiteto, designer, etc.).
 */
class Pedido extends Model
{
    protected $table = 'pedidos';

    protected $fillable = [
        'id_cliente',
        'id_usuario',
        'id_parceiro',
        'data_pedido',
        'status',
        'valor_total',
        'observacoes',
    ];

    protected $casts = [
        'data_pedido' => 'datetime',
        'valor_total' => 'decimal:2',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public function parceiro(): BelongsTo
    {
        return $this->belongsTo(Parceiro::class, 'id_parceiro');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PedidoItem::class, 'id_pedido');
    }
}
