<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegracaoBancariaConexao extends Model
{
    protected $table = 'integracao_bancaria_conexoes';

    protected $fillable = [
        'conta_financeira_id',
        'provedor',
        'ambiente',
        'status',
        'ultima_sincronizacao_em',
        'ultimo_periodo_inicio',
        'ultimo_periodo_fim',
        'ultimo_erro',
        'meta_json',
    ];

    protected $casts = [
        'ultima_sincronizacao_em' => 'datetime',
        'ultimo_periodo_inicio' => 'date',
        'ultimo_periodo_fim' => 'date',
        'meta_json' => 'array',
    ];

    public function contaFinanceira(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_financeira_id')->withDefault();
    }
}
