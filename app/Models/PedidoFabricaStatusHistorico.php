<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Histórico de mudanças de status do Pedido de Fábrica.
 *
 * @property int $id
 * @property int $pedido_fabrica_id
 * @property string $status
 * @property int|null $usuario_id
 * @property string|null $observacao
 */
class PedidoFabricaStatusHistorico extends Model
{
    protected $table = 'pedido_fabrica_status_historicos';

    /** @var array<int, string> */
    protected $fillable = [
        'pedido_fabrica_id',
        'status',
        'usuario_id',
        'observacao',
    ];

    public function pedidoFabrica(): BelongsTo
    {
        return $this->belongsTo(PedidoFabrica::class, 'pedido_fabrica_id');
    }
}
