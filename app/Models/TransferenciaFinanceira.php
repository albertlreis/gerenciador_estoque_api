<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransferenciaFinanceira extends Model
{
    use SoftDeletes;

    protected $table = 'transferencias_financeiras';

    protected $fillable = [
        'conta_origem_id',
        'conta_destino_id',
        'valor',
        'data_movimento',
        'observacoes',
        'status',
        'created_by',
    ];

    protected $casts = [
        'valor' => 'decimal:2',
        'data_movimento' => 'datetime',
    ];

    public function contaOrigem(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_origem_id')->withDefault();
    }

    public function contaDestino(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_destino_id')->withDefault();
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by')->withDefault();
    }

    /**
     * Lançamentos gerados por esta transferência (2 linhas no extrato).
     */
    public function lancamentos(): HasMany
    {
        return $this->hasMany(LancamentoFinanceiro::class, 'referencia_id', 'id')
            ->where('referencia_type', self::class);
    }
}
