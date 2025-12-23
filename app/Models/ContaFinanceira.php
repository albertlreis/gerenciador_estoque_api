<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conta financeira (banco, caixa, pix, etc.)
 */
class ContaFinanceira extends Model
{
    protected $table = 'contas_financeiras';

    protected $fillable = [
        'nome',
        'slug',
        'tipo',
        'banco_nome',
        'banco_codigo',
        'agencia',
        'agencia_dv',
        'conta',
        'conta_dv',
        'titular_nome',
        'titular_documento',
        'chave_pix',
        'moeda',
        'ativo',
        'padrao',
        'saldo_inicial',
        'observacoes',
        'meta_json',
    ];

    protected $casts = [
        'ativo'         => 'boolean',
        'padrao'        => 'boolean',
        'saldo_inicial' => 'decimal:2',
        'meta_json'     => 'array',
    ];

    /* =========================
     * Relations
     * ========================= */

    /** Lançamentos vinculados à conta */
    public function lancamentos(): HasMany
    {
        return $this->hasMany(LancamentoFinanceiro::class, 'conta_id');
    }

    /* =========================
     * Scopes
     * ========================= */

    public function scopeAtivas($q)
    {
        return $q->where('ativo', true);
    }

    public function scopeTipo($q, ?string $tipo)
    {
        return $tipo ? $q->where('tipo', $tipo) : $q;
    }

    /* =========================
     * Helpers
     * ========================= */

    public function identificacaoBancaria(): ?string
    {
        if (!$this->banco_nome) return null;

        return trim(sprintf(
            '%s (%s) Ag %s%s Cc %s%s',
            $this->banco_nome,
            $this->banco_codigo,
            $this->agencia,
            $this->agencia_dv ? "-{$this->agencia_dv}" : '',
            $this->conta,
            $this->conta_dv ? "-{$this->conta_dv}" : ''
        ));
    }
}
