<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstoqueReserva extends Model
{
    protected $table = 'estoque_reservas';

    protected $fillable = [
        'id_variacao',
        'id_deposito',
        'pedido_id',
        'pedido_item_id',
        'id_usuario',
        'quantidade',
        'quantidade_consumida',
        'status',
        'motivo',
        'data_expira',
    ];

    protected $casts = [
        'data_expira' => 'datetime',
        'quantidade' => 'int',
        'quantidade_consumida' => 'int',
    ];

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao');
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'id_deposito');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function pedidoItem(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class, 'pedido_item_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public function pendente(): int
    {
        return max(0, (int)$this->quantidade - (int)$this->quantidade_consumida);
    }
}
