<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ConciliacaoBancariaTransacao extends Model
{
    protected $table = 'conciliacao_bancaria_transacoes';

    protected $fillable = [
        'importacao_id',
        'conta_financeira_id',
        'fit_id',
        'identificador',
        'hash_unico',
        'origem',
        'origem_transacao_id',
        'data_movimento',
        'valor',
        'tipo_ofx',
        'checknum',
        'memo',
        'raw_json',
        'status',
        'candidato_tipo',
        'candidato_id',
        'candidato_score',
        'candidato_motivo',
        'candidato_json',
        'forma_pagamento',
        'pagamento_type',
        'pagamento_id',
        'lancamento_financeiro_id',
        'conciliado_em',
        'conciliado_por',
        'observacoes',
    ];

    protected $casts = [
        'data_movimento' => 'date',
        'valor' => 'decimal:2',
        'raw_json' => 'array',
        'candidato_json' => 'array',
        'conciliado_em' => 'datetime',
    ];

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(ConciliacaoBancariaImportacao::class, 'importacao_id');
    }

    public function contaFinanceira(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_financeira_id')->withDefault();
    }

    public function lancamentoFinanceiro(): BelongsTo
    {
        return $this->belongsTo(LancamentoFinanceiro::class, 'lancamento_financeiro_id')->withDefault();
    }

    public function pagamento(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'pagamento_type', 'pagamento_id');
    }

    public function isCredito(): bool
    {
        return (float) $this->valor > 0;
    }

    public function valorAbsoluto(): float
    {
        return abs((float) $this->valor);
    }
}
