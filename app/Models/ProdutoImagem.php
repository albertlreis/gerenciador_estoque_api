<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProdutoImagem extends Model
{
    protected $fillable = [
        'id_produto',
        'url',
        'principal'
    ];

    // Cada imagem pertence a um produto
    public function produto()
    {
        return $this->belongsTo(Produto::class, 'id_produto');
    }
}
