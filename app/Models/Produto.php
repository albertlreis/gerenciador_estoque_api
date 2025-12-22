<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $nome
 * @property bool $ativo
 * @property-read int $estoque_outlet_total
 */
class Produto extends Model
{
    protected $fillable = [
        'nome', 'descricao', 'id_categoria', 'id_fornecedor',
        'altura', 'largura', 'profundidade', 'peso',
        'ativo', 'manual_conservacao', 'motivo_desativacao', 'estoque_minimo',
    ];

    protected $appends = ['estoque_outlet_total'];

    /**
     * @return BelongsTo<Categoria, Produto>
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'id_categoria');
    }

    /**
     * @return BelongsTo<Fornecedor, Produto>
     */
    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class, 'id_fornecedor');
    }

    /**
     * @return HasMany<ProdutoVariacao>
     */
    public function variacoes(): HasMany
    {
        return $this->hasMany(ProdutoVariacao::class, 'produto_id');
    }

    /**
     * @return HasMany<ProdutoImagem>
     */
    public function imagens(): HasMany
    {
        return $this->hasMany(ProdutoImagem::class, 'id_produto')
            ->orderByDesc('principal')
            ->orderBy('id');
    }

    public function imagemPrincipal(): HasOne
    {
        return $this->hasOne(ProdutoImagem::class, 'id_produto')
            ->orderByDesc('principal')
            ->orderBy('id');
    }


    public function getEstoqueTotalAttribute(): int
    {
        $variacoes = $this->relationLoaded('variacoes') ? $this->variacoes : collect();

        return $variacoes->sum(function ($v) {
            if (!$v->relationLoaded('estoques')) return 0;
            return (int) $v->estoques->sum('quantidade');
        });
    }

    public function getEstoqueOutletTotalAttribute(): int
    {
        $variacoes = $this->relationLoaded('variacoes') ? $this->variacoes : collect();

        return $variacoes->reduce(function ($acc, $variacao) {
            return $acc + ($variacao->relationLoaded('outlets') ? $variacao->outlets->sum('quantidade') : 0);
        }, 0);
    }
}
