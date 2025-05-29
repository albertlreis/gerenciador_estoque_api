<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consignacao extends Model
{
    protected $table = 'consignacoes';

    protected $fillable = [
        'pedido_id',
        'produto_variacao_id',
        'quantidade',
        'data_envio',
        'prazo_resposta',
        'status',
    ];

    protected $casts = [
        'data_envio' => 'date',
        'prazo_resposta' => 'date',
    ];

    /**
     * Pedido relacionado à consignação.
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * Variação do produto consignado.
     */
    public function produtoVariacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class);
    }
}
