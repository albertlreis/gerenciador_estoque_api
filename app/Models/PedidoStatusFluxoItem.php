<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoStatusFluxoItem extends Model
{
    protected $table = 'pedido_status_fluxo_itens';

    protected $fillable = [
        'tipo_fluxo',
        'pedido_status_id',
        'ordem',
        'prazo_dias',
        'exige_previsao_manual',
        'ativo',
    ];

    protected $casts = [
        'ordem' => 'integer',
        'prazo_dias' => 'integer',
        'exige_previsao_manual' => 'boolean',
        'ativo' => 'boolean',
    ];

    public function statusDefinicao(): BelongsTo
    {
        return $this->belongsTo(PedidoStatusDefinicao::class, 'pedido_status_id');
    }
}
