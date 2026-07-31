<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoReconciliacaoItem extends Model
{
    public const ACAO_BAIXAR_E_ENTREGAR = 'BAIXAR_E_ENTREGAR';

    public const ACAO_DOCUMENTAR_SEM_BAIXA = 'DOCUMENTAR_SEM_BAIXA';

    public const ACAO_AJUSTAR_SALDO = 'AJUSTAR_SALDO';

    protected $table = 'pedido_reconciliacao_itens';

    protected $fillable = [
        'pedido_reconciliacao_id',
        'pedido_item_id',
        'produto_entrega_item_id',
        'estoque_movimentacao_id',
        'produto_entrega_evento_id',
        'acao',
        'classificacao_original',
        'id_variacao',
        'id_deposito',
        'quantidade',
        'saldo_sistema_antes',
        'saldo_fisico_confirmado',
        'saldo_sistema_depois',
        'status',
        'resultado_json',
        'aplicada_em',
        'estornada_em',
    ];

    protected $casts = [
        'resultado_json' => 'array',
        'aplicada_em' => 'datetime',
        'estornada_em' => 'datetime',
    ];

    public function reconciliacao(): BelongsTo
    {
        return $this->belongsTo(PedidoReconciliacao::class, 'pedido_reconciliacao_id');
    }

    public function pedidoItem(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class, 'pedido_item_id');
    }

    public function entregaItem(): BelongsTo
    {
        return $this->belongsTo(ProdutoEntregaItem::class, 'produto_entrega_item_id');
    }

    public function movimentacao(): BelongsTo
    {
        return $this->belongsTo(EstoqueMovimentacao::class, 'estoque_movimentacao_id');
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(ProdutoEntregaEvento::class, 'produto_entrega_evento_id');
    }
}
