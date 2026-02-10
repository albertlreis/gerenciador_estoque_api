<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Atributo de uma variação de produto (ex: cor = vermelho).
 *
 * Regras:
 * - atributo: salvo sempre "squish + lower + ascii" (padroniza 'Cor', 'COR', '  cor   ' -> 'cor')
 * - valor: salvo com "squish" (mantém caixa do valor, mas remove espaços excedentes)
 */
class ProdutoVariacaoAtributo extends Model
{
    protected $table = 'produto_variacao_atributos';

    protected $fillable = ['id_variacao', 'atributo', 'valor'];

    protected $appends = ['atributo_label'];

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao');
    }

    /** Normaliza a chave do atributo antes de salvar. */
    public function setAtributoAttribute($value): void
    {
        $this->attributes['atributo'] = (string) Str::of((string) $value)
            ->squish()
            ->lower()
            ->ascii();
    }

    /** Higieniza o valor (mantém caixa, remove espaçamentos estranhos). */
    public function setValorAttribute($value): void
    {
        $this->attributes['valor'] = (string) Str::of((string) $value)->squish();
    }

    /** Fornece uma label amigável para UI (ex.: 'cor_primaria' -> 'Cor Primaria'). */
    public function getAtributoLabelAttribute(): string
    {
        $attr = (string)($this->attributes['atributo'] ?? '');
        if ($attr === '') return '';

        return (string) Str::of($attr)->replace('_', ' ')->headline();
    }
}
