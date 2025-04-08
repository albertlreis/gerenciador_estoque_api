<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Atributo extends Model
{
    protected $fillable = [
        'nome'
    ];

    // Um atributo possui vários valores
    public function valores()
    {
        return $this->hasMany(AtributoValor::class, 'id_atributo');
    }
}
