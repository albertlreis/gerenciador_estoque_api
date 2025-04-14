<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Produto extends Model
{
    protected $fillable = [
        'nome',
        'descricao',
        'id_categoria',
        'ativo',
        'preco'
    ];

    // Cada produto pertence a uma categoria
    public function categoria()
    {
        return $this->belongsTo(Categoria::class, 'id_categoria');
    }

    // Um produto pode ter várias imagens
    public function imagens()
    {
        return $this->hasMany(ProdutoImagem::class, 'id_produto');
    }

    // Um produto pode ter diversas variações
    public function variacoes()
    {
        return $this->hasMany(ProdutoVariacao::class, 'id_produto');
    }
}
