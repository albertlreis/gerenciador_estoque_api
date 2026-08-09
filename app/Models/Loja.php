<?php

namespace App\Models;

use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loja extends Model
{
    protected $table = 'lojas';

    protected $fillable = [
        'codigo',
        'nome',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function conexoesContaAzul(): HasMany
    {
        return $this->hasMany(ContaAzulConexao::class, 'loja_id');
    }
}
