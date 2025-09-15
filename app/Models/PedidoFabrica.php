<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $status
 * @property \Carbon\CarbonImmutable|\Carbon\Carbon|null $data_previsao_entrega
 * @property string|null $observacoes
 */
class PedidoFabrica extends Model
{
    protected $table = 'pedidos_fabrica';

    /** @var array<int, string> */
    protected $fillable = [
        'status',
        'data_previsao_entrega',
        'observacoes',
    ];

    protected $casts = [
        'data_previsao_entrega' => 'date',
    ];

    /** @return HasMany<PedidoFabricaItem> */
    public function itens(): HasMany
    {
        return $this->hasMany(PedidoFabricaItem::class, 'pedido_fabrica_id');
    }

    /** @return HasMany<PedidoFabricaStatusHistorico> */
    public function historicos(): HasMany
    {
        return $this->hasMany(PedidoFabricaStatusHistorico::class, 'pedido_fabrica_id')
            ->orderByDesc('created_at');
    }

    /** @return HasMany<PedidoFabricaEntrega> */
    public function entregas(): HasMany
    {
        return $this->hasMany(PedidoFabricaEntrega::class, 'pedido_fabrica_id')
            ->orderByDesc('created_at');
    }

    /**
     * Recalcula o status com base nos itens (para refletir parcial/entregue).
     * Regras:
     * - se todos itens entregues (quantidade_entregue == quantidade) => 'entregue'
     * - se algum entregue (>0) e algum pendente => 'parcial'
     * - caso contrário mantém o atual (pendente/enviado/cancelado)
     */
    public function recomputarStatusPorItens(): string
    {
        $itens = $this->itens()->get(['quantidade', 'quantidade_entregue']);
        if ($itens->isEmpty()) {
            return $this->status;
        }

        $todosEntregues = $itens->every(fn($i) => (int)$i->quantidade_entregue >= (int)$i->quantidade);
        if ($todosEntregues) {
            return 'entregue';
        }

        $algumEntregue = $itens->some(fn($i) => (int)$i->quantidade_entregue > 0);
        if ($algumEntregue) {
            return 'parcial';
        }

        return $this->status;
    }

}
