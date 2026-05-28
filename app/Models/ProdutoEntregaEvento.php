<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProdutoEntregaEvento extends Model
{
    protected $table = 'produto_entrega_eventos';

    public const DEMANDA_CRIADA = 'demanda_criada';
    public const RESERVA_CRIADA = 'reserva_criada';
    public const RESERVA_CANCELADA = 'reserva_cancelada';
    public const RECEBIDO_ESTOQUE = 'recebido_estoque';
    public const EXPEDIDO_CLIENTE = 'expedido_cliente';
    public const ENTREGUE_CLIENTE = 'entregue_cliente';
    public const ENVIADO_CONSIGNACAO = 'enviado_consignacao';
    public const RETORNADO_CONSIGNACAO = 'retornado_consignacao';
    public const ENVIADO_ASSISTENCIA = 'enviado_assistencia';
    public const RETORNADO_ASSISTENCIA = 'retornado_assistencia';
    public const DEVOLUCAO_RECEBIDA = 'devolucao_recebida';
    public const CANCELADO = 'cancelado';
    public const ESTORNADO = 'estornado';

    protected $fillable = [
        'produto_entrega_item_id',
        'tipo_evento',
        'quantidade',
        'id_deposito_origem',
        'id_deposito_destino',
        'estoque_reserva_id',
        'estoque_movimentacao_id',
        'usuario_id',
        'observacao',
        'metadata_json',
        'idempotency_key',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'quantidade' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProdutoEntregaItem::class, 'produto_entrega_item_id');
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(EstoqueReserva::class, 'estoque_reserva_id');
    }

    public function movimentacao(): BelongsTo
    {
        return $this->belongsTo(EstoqueMovimentacao::class, 'estoque_movimentacao_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
