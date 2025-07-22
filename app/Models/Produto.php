<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Produto extends Model
{
    protected $fillable = [
        'nome', 'descricao', 'id_categoria', 'id_fornecedor',
        'altura', 'largura', 'profundidade', 'peso',
        'ativo', 'manual_conservacao', 'motivo_desativacao', 'estoque_minimo'
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
        return $this->hasMany(ProdutoImagem::class, 'id_produto')
            ->orderByDesc('principal')
            ->orderBy('id');
    }

    public function imagemPrincipal()
    {
        return $this->hasOne(ProdutoImagem::class, 'id_produto')->where('principal', true);
    }

    public function getEstoqueTotalAttribute(): int
    {
        $variacoes = $this->relationLoaded('variacoes') ? $this->variacoes : collect();

        return $variacoes->sum(fn($v) => $v->relationLoaded('estoque') ? ($v->estoque->quantidade ?? 0) : 0);
    }

    public function getEstoqueOutletTotalAttribute(): int
    {
        $variacoes = $this->relationLoaded('variacoes') ? $this->variacoes : collect();

        return $variacoes->reduce(function ($acc, $variacao) {
            return $acc + ($variacao->relationLoaded('outlets') ? $variacao->outlets->sum('quantidade') : 0);
        }, 0);
    }
}
