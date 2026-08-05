<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComunicacaoJornadaCanal extends Model
{
    protected $table = 'comunicacao_jornada_canais';
    protected $fillable = ['jornada_id', 'canal', 'template_codigo', 'ativo'];
    protected $casts = ['ativo' => 'boolean'];
}
