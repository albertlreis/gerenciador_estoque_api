<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $pedido_fabrica_id
 * @property int $produto_variacao_id
 * @property int $quantidade
 * @property int $quantidade_entregue
 * @property int|null $deposito_id
 * @property int|null $pedido_venda_id
 * @property string|null $observacoes
 */
class PedidoFabricaItem extends Model
{
    protected $table = 'pedidos_fabrica_itens';

    /** @var array<int, string> */
    protected $fillable = [
        'pedido_fabrica_id',
        'produto_variacao_id',
        'quantidade',
        'quantidade_entregue',
        'deposito_id',
        'pedido_venda_id',
        'observacoes',
    ];

    public function pedidoFabrica(): BelongsTo
    {
        return $this->belongsTo(PedidoFabrica::class, 'pedido_fabrica_id');
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class);
    }

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'produto_variacao_id');
    }

    public function pedidoVenda(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_venda_id');
    }
}
