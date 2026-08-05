<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComunicacaoJornada extends Model
{
    protected $table = 'comunicacao_jornadas';

    protected $fillable = ['codigo', 'nome', 'tipo', 'ativo', 'timezone', 'agenda', 'versao', 'created_by', 'updated_by'];

    protected $casts = ['ativo' => 'boolean', 'agenda' => 'array', 'versao' => 'integer'];

    public function eventos(): HasMany
    {
        return $this->hasMany(ComunicacaoJornadaEvento::class, 'jornada_id');
    }

    public function canais(): HasMany
    {
        return $this->hasMany(ComunicacaoJornadaCanal::class, 'jornada_id');
    }
}
