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

    /**
     * Estoques da variação (normalmente 1 por depósito).
     */
    public function estoques(): HasMany
    {
        return $this->hasMany(Estoque::class, 'id_variacao');
    }

    /**
     * Estoque total (somatório).
     *
     * Preferências:
     * - Se a query já trouxe `quantidade_estoque` via withSum, usa ela (mais barato).
     * - Senão, se relação `estoques` estiver carregada, soma em memória.
     * - Por fim, fallback: 0.
     */
    public function getEstoqueTotalAttribute(): int
    {
        // 1) veio do withSum?
        if (array_key_exists('quantidade_estoque', $this->attributes)) {
            return (int) ($this->attributes['quantidade_estoque'] ?? 0);
        }

        // 2) relação carregada?
        if ($this->relationLoaded('estoques')) {
            return (int) $this->estoques->sum('quantidade');
        }

        return 0;
    }

    public function getNomeCompletoAttribute(): string
    {
        // Evita lazy loading
        $produto = $this->relationLoaded('produto') ? $this->produto : $this->getRelationValue('produto');
        $produtoNome = trim($produto?->nome ?? '');

        // Garante atributos carregados
        $atributos = $this->relationLoaded('atributos') ? $this->atributos : collect();

        if ($atributos->isNotEmpty()) {
            // Agrupa atributos por nome
            $agrupados = $atributos->groupBy('atributo')->map(function ($itens, $atributo) {
                $valores = $itens->pluck('valor')->filter()->unique()->join(', ');
                return ucfirst($atributo) . ': ' . $valores;
            });

            // Junta tudo num único texto, separado por " | "
            $atributosTexto = $agrupados->join(' | ');

            return trim("{$produtoNome} ({$atributosTexto})");
        }

        // Se não tiver atributos, retorna o nome base da variação ou do produto
        if (!empty($this->nome)) {
            return trim("{$produtoNome} - {$this->nome}");
        }

        return $produtoNome ?: '-';
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

    /**
     * Estoques da variação com relações necessárias para a tela.
     *
     * IMPORTANTE:
     * Evite colocar `with()` aqui para não forçar eager loading em qualquer lugar que use a relação.
     * Deixe o eager loading no Repository/Service.
     */
    public function estoquesComLocalizacao(): HasMany
    {
        return $this->hasMany(Estoque::class, 'id_variacao');
    }

}
