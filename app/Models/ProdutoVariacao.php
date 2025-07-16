<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Representa uma variação específica de um produto,
 * com preço, referencia e código de barras próprios.
 */
class ProdutoVariacao extends Model
{
    protected $table = 'produto_variacoes';

    protected $fillable = [
        'produto_id', 'referencia', 'nome', 'preco', 'custo', 'codigo_barras'
    ];

    protected $appends = [
        'nome_completo',
        'estoque_total',
        'estoque_outlet_total',
        'outlet_restante_total',
    ];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }

    public function atributos(): HasMany
    {
        return $this->hasMany(ProdutoVariacaoAtributo::class, 'id_variacao');
    }

    public function estoque(): HasOne
    {
        return $this->hasOne(Estoque::class, 'id_variacao');
    }

    public function getEstoqueTotalAttribute(): int
    {
        return $this->estoque->quantidade ?? 0;
    }

    public function getNomeCompletoAttribute(): string
    {
        // Evita lazy loading caso não esteja carregado
        $produto = $this->relationLoaded('produto') ? $this->produto : null;
        $produtoNome = $produto?->nome ?? '';

        // Evita lazy loading também para atributos
        $atributos = $this->relationLoaded('atributos') ? $this->atributos : collect();

        $atributosTexto = $atributos->map(fn($attr) => "$attr->atributo: $attr->valor")
            ->implode(' - ');

        $complemento = trim($atributosTexto ?: '');

        return trim("$produtoNome - $complemento") ?: '-';
    }

    public function outlets(): HasMany
    {
        return $this->hasMany(ProdutoVariacaoOutlet::class, 'produto_variacao_id');
    }

    public function outlet(): HasOne
    {
        return $this->hasOne(ProdutoVariacaoOutlet::class, 'produto_variacao_id')
            ->where('quantidade_restante', '>', 0)
            ->latest();
    }

    public function getEstoqueOutletTotalAttribute(): int
    {
        return $this->outlets->sum('quantidade') ?? 0;
    }

    public function getOutletRestanteTotalAttribute(): int
    {
        return $this->outlets->sum('quantidade_restante') ?? 0;
    }

    public function estoquesComLocalizacao(): HasMany
    {
        return $this->hasMany(Estoque::class, 'id_variacao')
            ->with(['localizacao', 'deposito']);
    }

}
