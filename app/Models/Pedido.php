<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Representa um pedido no sistema.
 */
class Pedido extends Model
{
    protected $table = 'pedidos';

    protected $fillable = [
        'id_cliente',
        'id_usuario',
        'id_parceiro',
        'numero_externo',
        'data_pedido',
        'valor_total',
        'observacoes',
        'prazo_dias_uteis',
        'data_limite_entrega',
    ];

    protected $casts = [
        'data_pedido' => 'datetime',
        'valor_total' => 'decimal:2',
        'data_limite_entrega' => 'date',
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

    public function consignacoes(): HasMany
    {
        return $this->hasMany(Consignacao::class);
    }

    public function isConsignado(): bool
    {
        return $this->consignacoes()->exists();
    }

    /**
     * Retorna o histórico completo de status do pedido.
     */
    public function historicoStatus(): HasMany
    {
        return $this->hasMany(PedidoStatusHistorico::class);
    }

    /**
     * Retorna o status mais recente do pedido.
     */
    public function statusAtual(): HasOne
    {
        return $this->hasOne(PedidoStatusHistorico::class, 'pedido_id')->latestOfMany();
    }

    protected function status(): Attribute
    {
        return Attribute::get(fn () => $this->statusAtual?->status);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Devolucao>
     */
    public function devolucoes(): HasMany
    {
        return $this->hasMany(Devolucao::class);
    }
}
