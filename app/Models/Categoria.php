<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    protected $fillable = [
        'nome',
        'descricao'
    ];

    public function produtos(): HasMany
    {
        return $this->hasMany(Produto::class, 'id_categoria');
    }

    public function subcategorias()
    {
        return $this->hasMany(Categoria::class, 'categoria_pai_id');
    }

    public function pai()
    {
        return $this->belongsTo(Categoria::class, 'categoria_pai_id');
    }
}
