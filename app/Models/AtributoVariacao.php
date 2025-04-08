<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AtributoVariacao extends Model
{
    protected $fillable = [
        'id_variacao',
        'id_atributo_valor'
    ];

    // Cada registro vincula uma variação a um valor de atributo
    public function produtoVariacao()
    {
        return $this->belongsTo(ProdutoVariacao::class, 'id_variacao');
    }

    public function atributoValor()
    {
        return $this->belongsTo(AtributoValor::class, 'id_atributo_valor');
    }
}
