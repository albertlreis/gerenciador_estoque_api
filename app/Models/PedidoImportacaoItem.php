<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoImportacaoItem extends Model
{
    protected $table = 'pedido_importacao_itens';

    protected $fillable = [
        'pedido_importacao_id',
        'pedido_id',
        'pedido_item_id',
        'produto_id',
        'produto_variacao_id',
        'acao',
        'dados_importados_json',
        'dados_confirmados_json',
    ];

    protected $casts = [
        'dados_importados_json' => 'array',
        'dados_confirmados_json' => 'array',
    ];

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(PedidoImportacao::class, 'pedido_importacao_id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function pedidoItem(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class, 'pedido_item_id');
    }
}
