<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pedido extends Model
{
    protected $table = 'pedidos';

    public const TIPO_VENDA     = 'venda';
    public const TIPO_REPOSICAO = 'reposicao';

    protected $fillable = [
        'tipo',
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

    public function isVenda(): bool
    {
        return ($this->tipo ?? self::TIPO_VENDA) === self::TIPO_VENDA;
    }

    public function isReposicao(): bool
    {
        return ($this->tipo ?? self::TIPO_VENDA) === self::TIPO_REPOSICAO;
    }

    public function cliente(): BelongsTo
    {
        // cliente opcional para reposição
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

    public function historicoStatus(): HasMany
    {
        return $this->hasMany(PedidoStatusHistorico::class);
    }

    public function statusAtual(): HasOne
    {
        return $this->hasOne(PedidoStatusHistorico::class, 'pedido_id')->latestOfMany();
    }

    protected function status(): Attribute
    {
        return Attribute::get(fn () => $this->statusAtual?->status);
    }

    public function devolucoes(): HasMany
    {
        return $this->hasMany(Devolucao::class);
    }

    public function pedidosFabricaItens(): HasMany
    {
        return $this->hasMany(PedidoFabricaItem::class, 'pedido_venda_id');
    }

    public function pedidosFabrica(): HasManyThrough
    {
        return $this->hasManyThrough(
            PedidoFabrica::class,
            PedidoFabricaItem::class,
            'pedido_venda_id',
            'id',
            'id',
            'pedido_fabrica_id'
        );
    }
}
