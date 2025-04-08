<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProdutoVariacao extends Model
{
    protected $fillable = [
        'id_produto',
        'sku',
        'nome',
        'preco',
        'custo',
        'peso',
        'altura',
        'largura',
        'profundidade',
        'codigo_barras'
    ];

    // Cada variação pertence a um produto
    public function produto()
    {
        return $this->belongsTo(Produto::class, 'id_produto');
    }

    // Uma variação possui relacionamentos com os valores de atributos
    public function atributoVariacoes()
    {
        return $this->hasMany(AtributoVariacao::class, 'id_variacao');
    }

    // Relacionamento com estoque
    public function estoque()
    {
        return $this->hasMany(Estoque::class, 'id_variacao');
    }

    // Relacionamento com as movimentações de estoque
    public function movimentacoes()
    {
        return $this->hasMany(EstoqueMovimentacao::class, 'id_variacao');
    }
}
