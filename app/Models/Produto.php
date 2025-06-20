<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Produto extends Model
{
    protected $fillable = [
        'nome', 'descricao', 'id_categoria', 'id_fornecedor',
        'altura', 'largura', 'profundidade', 'peso', 'ativo'
    ];

    protected $appends = ['estoque_outlet_total'];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'id_categoria');
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class, 'id_fornecedor');
    }

    public function variacoes(): HasMany
    {
        return $this->hasMany(ProdutoVariacao::class, 'produto_id');
    }

    public function imagens(): HasMany
    {
        return $this->hasMany(ProdutoImagem::class, 'id_produto');
    }

    public function imagemPrincipal()
    {
        return $this->hasOne(ProdutoImagem::class, 'id_produto')->where('principal', true);
    }

    public function getEstoqueTotalAttribute(): int
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();

        return $variacoes
            ->map(fn($v) => $v->estoque?->quantidade ?? 0)
            ->sum();
    }

    public function getEstoqueOutletTotalAttribute(): int
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();

        return $variacoes->reduce(function ($acc, $variacao) {
            return $acc + ($variacao->outlets?->sum('quantidade') ?? 0);
        }, 0);
    }
}
