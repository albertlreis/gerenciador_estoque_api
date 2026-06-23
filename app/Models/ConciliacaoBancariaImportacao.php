<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConciliacaoBancariaImportacao extends Model
{
    protected $table = 'conciliacao_bancaria_importacoes';

    protected $fillable = [
        'conta_financeira_id',
        'banco_codigo',
        'banco_nome',
        'agencia',
        'conta',
        'conta_dv',
        'moeda',
        'data_inicio',
        'data_fim',
        'saldo_final',
        'saldo_final_em',
        'arquivo_hash',
        'status',
        'resumo_json',
        'created_by',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'saldo_final' => 'decimal:2',
        'saldo_final_em' => 'datetime',
        'resumo_json' => 'array',
    ];

    public function contaFinanceira(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_financeira_id')->withDefault();
    }

    public function transacoes(): HasMany
    {
        return $this->hasMany(ConciliacaoBancariaTransacao::class, 'importacao_id');
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by')->withDefault();
    }
}
