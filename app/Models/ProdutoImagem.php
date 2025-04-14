<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProdutoImagem extends Model
{
    protected $table = 'produto_imagens';

    protected $fillable = [
        'id_produto',
        'url',
        'principal'
    ];

    protected $casts = [
        'principal' => 'boolean'
    ];

    // Cada imagem pertence a um produto
    public function produto()
    {
        return $this->belongsTo(Produto::class, 'id_produto');
    }

    public function getUrlCompletaAttribute()
    {
        // Usar a mesma env para montar a URL
        return url(env('PRODUCT_IMAGES_FOLDER', 'uploads/produtos') . '/' . $this->url);
    }

}
