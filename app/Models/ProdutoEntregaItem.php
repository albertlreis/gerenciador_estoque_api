<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProdutoEntregaItem extends Model
{
    protected $table = 'produto_entrega_itens';

    public const STATUS_AGUARDANDO_ESTOQUE = 'aguardando_estoque';
    public const STATUS_RESERVADO = 'reservado';
    public const STATUS_PRONTO_EXPEDICAO = 'pronto_expedicao';
    public const STATUS_EXPEDIDO_PARCIAL = 'expedido_parcial';
    public const STATUS_EXPEDIDO = 'expedido';
    public const STATUS_ENTREGUE_PARCIAL = 'entregue_parcial';
    public const STATUS_ENTREGUE = 'entregue';
    public const STATUS_RECEBIDO_PARCIAL = 'recebido_parcial';
    public const STATUS_RECEBIDO = 'recebido';
    public const STATUS_CANCELADO = 'cancelado';
    public const STATUS_BLOQUEADO_REVISAO = 'bloqueado_revisao';

    public const ORIGEM_PEDIDO = 'pedido';
    public const ORIGEM_PEDIDO_FABRICA = 'pedido_fabrica';
    public const ORIGEM_CONSIGNACAO = 'consignacao';
    public const ORIGEM_ASSISTENCIA = 'assistencia';
    public const ORIGEM_DEVOLUCAO = 'devolucao';

    protected $fillable = [
        'tipo_origem',
        'origem_id',
        'pedido_id',
        'pedido_item_id',
        'pedido_fabrica_item_id',
        'consignacao_id',
        'assistencia_item_id',
        'devolucao_item_id',
        'id_variacao',
        'quantidade_total',
        'quantidade_reservada',
        'quantidade_recebida',
        'quantidade_expedida',
        'quantidade_entregue',
        'id_deposito_origem',
        'id_deposito_destino',
        'status',
        'previsao_entrega',
        'bloqueio_motivo',
    ];

    protected $casts = [
        'previsao_entrega' => 'date',
        'quantidade_total' => 'integer',
        'quantidade_reservada' => 'integer',
        'quantidade_recebida' => 'integer',
        'quantidade_expedida' => 'integer',
        'quantidade_entregue' => 'integer',
    ];

    public function eventos(): HasMany
    {
        return $this->hasMany(ProdutoEntregaEvento::class, 'produto_entrega_item_id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function pedidoItem(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class, 'pedido_item_id');
    }

    public function pedidoFabricaItem(): BelongsTo
    {
        return $this->belongsTo(PedidoFabricaItem::class, 'pedido_fabrica_item_id');
    }

    public function consignacao(): BelongsTo
    {
        return $this->belongsTo(Consignacao::class, 'consignacao_id');
    }

    public function assistenciaItem(): BelongsTo
    {
        return $this->belongsTo(AssistenciaChamadoItem::class, 'assistencia_item_id');
    }

    public function devolucaoItem(): BelongsTo
    {
        return $this->belongsTo(DevolucaoItem::class, 'devolucao_item_id');
    }

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao');
    }

    public function depositoOrigem(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'id_deposito_origem');
    }

    public function depositoDestino(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'id_deposito_destino');
    }
}
