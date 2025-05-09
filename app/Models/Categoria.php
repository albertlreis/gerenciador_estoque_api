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

    // Uma categoria possui vários produtos
    public function produtos(): HasMany
    {
        return $this->hasMany(Produto::class, 'id_categoria');
    }
}
