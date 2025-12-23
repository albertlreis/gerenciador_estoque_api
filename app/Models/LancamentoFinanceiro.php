<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $descricao
 * @property string $tipo   receita|despesa
 * @property string $status pendente|pago|cancelado
 * @property float|string $valor
 * @property Carbon $data_vencimento
 * @property Carbon|null $data_pagamento
 */
class LancamentoFinanceiro extends Model
{
    use SoftDeletes;

    protected $table = 'lancamentos_financeiros';

    protected $fillable = [
        'descricao',
        'tipo',
        'status',
        'categoria_id',
        'conta_id',
        'valor',
        'data_vencimento',
        'data_pagamento',
        'competencia',
        'observacoes',
        'referencia_type',
        'referencia_id',
        'created_by',
    ];

    protected $casts = [
        'valor'          => 'decimal:2',
        'data_vencimento'=> 'datetime',
        'data_pagamento' => 'datetime',
        'competencia'    => 'date',
    ];

    /** Atrasado é derivado: pendente + vencimento no passado */
    public function getAtrasadoAttribute(): bool
    {
        if ($this->status !== 'pendente') return false;
        if (!$this->data_vencimento) return false;
        return $this->data_vencimento->lt(now());
    }

    // Ajuste os Models conforme seu projeto
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
}
