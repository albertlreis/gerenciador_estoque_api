<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoria financeira (receita/despesa), com hierarquia.
 */
class CategoriaFinanceira extends Model
{
    protected $table = 'categorias_financeiras';

    protected $fillable = [
        'nome',
        'slug',
        'tipo',
        'categoria_pai_id',
        'ordem',
        'ativo',
        'padrao',
        'meta_json',
    ];

    protected $casts = [
        'ativo'     => 'boolean',
        'padrao'    => 'boolean',
        'ordem'     => 'integer',
        'meta_json' => 'array',
    ];

    /* =========================
     * Relations
     * ========================= */

    /** Categoria pai */
    public function pai(): BelongsTo
    {
        return $this->belongsTo(self::class, 'categoria_pai_id');
    }

    /** Subcategorias */
    public function filhas(): HasMany
    {
        return $this->hasMany(self::class, 'categoria_pai_id')
            ->orderBy('ordem')
            ->orderBy('nome');
    }

    /** Lançamentos vinculados */
    public function lancamentos(): HasMany
    {
        return $this->hasMany(LancamentoFinanceiro::class, 'categoria_id');
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

    public function isReceita(): bool
    {
        return $this->tipo === 'receita';
    }

    public function isDespesa(): bool
    {
        return $this->tipo === 'despesa';
    }
}
