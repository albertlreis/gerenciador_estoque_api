<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComunicacaoJornadaEvento extends Model
{
    protected $table = 'comunicacao_jornada_eventos';
    protected $fillable = ['jornada_id', 'evento_codigo'];
}
