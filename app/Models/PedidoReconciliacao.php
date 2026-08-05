<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoReconciliacao extends Model
{
    protected $table = 'pedido_reconciliacoes';

    protected $fillable = ['pedido_id', 'usuario_id', 'idempotency_key', 'fonte_verdade', 'justificativa', 'evidencia', 'snapshot_json', 'status', 'aplicada_em'];

    protected $casts = ['snapshot_json' => 'array', 'aplicada_em' => 'datetime'];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PedidoReconciliacaoItem::class, 'pedido_reconciliacao_id');
    }
}
