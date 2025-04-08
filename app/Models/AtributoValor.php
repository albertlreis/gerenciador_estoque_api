<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AtributoValor extends Model
{
    protected $fillable = [
        'id_atributo',
        'valor'
    ];

    // Cada valor pertence a um atributo
    public function atributo()
    {
        return $this->belongsTo(Atributo::class, 'id_atributo');
    }

    // Opcional: se quiser relacionar com as variações que usam esse valor
    public function atributoVariacoes()
    {
        return $this->hasMany(AtributoVariacao::class, 'id_atributo_valor');
    }
}
