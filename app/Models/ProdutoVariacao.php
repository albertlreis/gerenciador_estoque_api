<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

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

    protected $casts = [
        'preco' => 'float',
        'custo' => 'float',
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
     * Estoque singular (compatibilidade com pontos legados).
     */
    public function estoque(): HasOne
    {
        return $this->hasOne(Estoque::class, 'id_variacao');
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
        // 1) veio de alias com withSum([... as quantidade_estoque], 'quantidade')?
        if (array_key_exists('quantidade_estoque', $this->attributes)) {
            return (int) ($this->attributes['quantidade_estoque'] ?? 0);
        }

        // 2) veio de withSum('estoques','quantidade')? (nome padrão do Laravel)
        if (array_key_exists('estoques_sum_quantidade', $this->attributes)) {
            return (int) ($this->attributes['estoques_sum_quantidade'] ?? 0);
        }

        // 3) relação carregada?
        if ($this->relationLoaded('estoques')) {
            return (int) $this->estoques->sum('quantidade');
        }

        return 0;
    }

    public function getNomeCompletoAttribute(): string
    {
        // Evitar lazy loading: só usa produto se estiver eager loaded ou se veio por select alias
        $produtoNome = '';

        if ($this->relationLoaded('produto')) {
            $produtoNome = trim((string)($this->produto?->nome ?? ''));
        } elseif (array_key_exists('produto_nome', $this->attributes)) {
            $produtoNome = trim((string)($this->attributes['produto_nome'] ?? ''));
        }

        // Atributos: só usar se estiverem carregados (evita query)
        $atributos = $this->relationLoaded('atributos') ? $this->atributos : collect();

        // Base do nome quando produto não veio carregado
        $nomeVar = trim((string)($this->nome ?? ''));
        $base = $produtoNome !== '' ? $produtoNome : ($nomeVar !== '' ? $nomeVar : '-');

        if ($atributos->isNotEmpty()) {
            // Ordena por atributo e depois ordena valores para ficar determinístico
            $agrupados = $atributos
                ->groupBy('atributo')
                ->sortKeys()
                ->map(function ($itens, $atributo) {
                    $valores = $itens->pluck('valor')
                        ->filter(fn($v) => $v !== null && $v !== '')
                        ->unique()
                        ->sort()
                        ->values()
                        ->join(', ');

                    $label = (string) Str::of((string)$atributo)->replace('_', ' ')->headline();

                    return trim($label . ': ' . $valores);
                })
                ->filter()
                ->values();

            $atributosTexto = $agrupados->join(' | ');

            return $atributosTexto !== '' ? trim("{$base} ({$atributosTexto})") : $base;
        }

        // Se não tiver atributos:
        // - Se produto existe e variação tem nome, usa "Produto - Variação"
        if ($produtoNome !== '' && $nomeVar !== '') {
            return trim("{$produtoNome} - {$nomeVar}");
        }

        return $base;
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

    /**
     * Total de quantidade em outlets.
     *
     * Preferências:
     * - Se veio por withSum (alias), usa.
     * - Se veio por withSum padrão do Laravel, usa.
     * - Se relação carregada, soma em memória.
     * - fallback 0.
     */
    public function getEstoqueOutletTotalAttribute(): int
    {
        // alias recomendado: withSum('outlets as quantidade_outlet', 'quantidade')
        if (array_key_exists('quantidade_outlet', $this->attributes)) {
            return (int) ($this->attributes['quantidade_outlet'] ?? 0);
        }

        // padrão do Laravel: withSum('outlets','quantidade')
        if (array_key_exists('outlets_sum_quantidade', $this->attributes)) {
            return (int) ($this->attributes['outlets_sum_quantidade'] ?? 0);
        }

        if ($this->relationLoaded('outlets')) {
            return (int) $this->outlets->sum('quantidade');
        }

        return 0;
    }

    /**
     * Total restante de outlets.
     */
    public function getOutletRestanteTotalAttribute(): int
    {
        // alias recomendado: withSum('outlets as quantidade_outlet_restante', 'quantidade_restante')
        if (array_key_exists('quantidade_outlet_restante', $this->attributes)) {
            return (int) ($this->attributes['quantidade_outlet_restante'] ?? 0);
        }

        // padrão do Laravel: withSum('outlets','quantidade_restante')
        if (array_key_exists('outlets_sum_quantidade_restante', $this->attributes)) {
            return (int) ($this->attributes['outlets_sum_quantidade_restante'] ?? 0);
        }

        if ($this->relationLoaded('outlets')) {
            return (int) $this->outlets->sum('quantidade_restante');
        }

        return 0;
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
