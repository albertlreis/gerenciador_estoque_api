<?php

namespace App\Models;

use App\Enums\LancamentoStatus;
use App\Enums\LancamentoTipo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LancamentoFinanceiro extends Model
{
    use SoftDeletes;

    protected $table = 'lancamentos_financeiros';

    protected $fillable = [
        'descricao',
        'tipo',            // receita|despesa
        'status',          // confirmado|cancelado

        'categoria_id',
        'centro_custo_id',
        'conta_id',

        'valor',

        'data_pagamento',
        'data_movimento',
        'competencia',

        'observacoes',

        'referencia_type',
        'referencia_id',

        'pagamento_type',
        'pagamento_id',

        'created_by',
    ];

    protected $casts = [
        'valor'          => 'decimal:2',
        'data_pagamento' => 'datetime',
        'data_movimento' => 'datetime',
        'competencia'    => 'date',

        'tipo'   => LancamentoTipo::class,
        'status' => LancamentoStatus::class,
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaFinanceira::class, 'categoria_id')->withDefault();
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_id')->withDefault();
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by')->withDefault();
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id')->withDefault();
    }
}
