<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DespesaRecorrenteExecucao extends Model
{
    protected $table = 'despesa_recorrente_execucoes';

    protected $fillable = [
        'despesa_recorrente_id',
        'competencia',
        'data_prevista',
        'data_geracao',
        'conta_pagar_id',
        'status',
        'erro_msg',
        'meta_json',
    ];

    protected $casts = [
        'competencia' => 'date',
        'data_prevista' => 'date',
        'data_geracao' => 'datetime',
        'meta_json' => 'array',
    ];

    public function despesaRecorrente(): BelongsTo
    {
        return $this->belongsTo(DespesaRecorrente::class, 'despesa_recorrente_id');
    }

    public function contaPagar(): BelongsTo
    {
        return $this->belongsTo(ContaPagar::class, 'conta_pagar_id')->withDefault();
    }
}
