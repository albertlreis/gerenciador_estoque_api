<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstoqueMovimentacao extends Model
{
    protected $table = 'estoque_movimentacoes';

    protected $fillable = [
        'id_variacao',
        'id_deposito_origem',
        'id_deposito_destino',
        'tipo',
        'quantidade',
        'observacao',
        'data_movimentacao',
        'id_usuario',
        'lote_id',
        'ref_type',
        'ref_id',
        'pedido_id',
        'pedido_item_id',
        'reserva_id',
    ];

    protected $casts = [
        'data_movimentacao' => 'datetime',
    ];

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

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function pedidoItem(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class, 'pedido_item_id');
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(EstoqueReserva::class, 'reserva_id');
    }

}
