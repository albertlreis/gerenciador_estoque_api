<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\ProdutoVariacao;

class ProdutoVariacaoVinculo extends Model
{
    protected $fillable = [
        'descricao_xml',
        'produto_variacao_id',
    ];

    public function variacao()
    {
        return $this->belongsTo(ProdutoVariacao::class, 'produto_variacao_id');
    }
}
