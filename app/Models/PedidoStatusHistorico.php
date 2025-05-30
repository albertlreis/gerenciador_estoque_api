<?php

namespace App\Models;

use App\Enums\PedidoStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Representa uma entrada do histórico de status de um pedido.
 */
class PedidoStatusHistorico extends Model
{
    use HasFactory;

    protected $table = 'pedido_status_historico';

    protected $fillable = [
        'pedido_id',
        'status',
        'data_status',
        'usuario_id',
        'observacoes',
    ];

    protected $casts = [
        'data_status' => 'datetime',
        'status' => PedidoStatus::class,
    ];

    /**
     * Pedido relacionado ao status.
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * Usuário responsável pela alteração do status.
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
