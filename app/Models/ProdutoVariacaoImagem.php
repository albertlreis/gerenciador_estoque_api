<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProdutoVariacaoImagem extends Model
{
    protected $table = 'produto_variacao_imagens';

    protected $fillable = [
        'id_variacao',
        'url',
    ];

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao');
    }
}

