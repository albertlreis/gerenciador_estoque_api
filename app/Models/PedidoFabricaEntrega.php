<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de uma entrega (baixa) de item de Pedido de Fábrica.
 *
 * @property int $id
 * @property int $pedido_fabrica_id
 * @property int $pedido_fabrica_item_id
 * @property int|null $deposito_id
 * @property int $quantidade
 * @property int|null $usuario_id
 * @property string|null $observacao
 * @property \Carbon\Carbon $created_at
 */
class PedidoFabricaEntrega extends Model
{
    protected $table = 'pedido_fabrica_entregas';

    /** @var array<int, string> */
    protected $fillable = [
        'pedido_fabrica_id',
        'pedido_fabrica_item_id',
        'deposito_id',
        'quantidade',
        'usuario_id',
        'observacao',
    ];

    public function pedidoFabrica(): BelongsTo
    {
        return $this->belongsTo(PedidoFabrica::class, 'pedido_fabrica_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PedidoFabricaItem::class, 'pedido_fabrica_item_id');
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'deposito_id');
    }
}
