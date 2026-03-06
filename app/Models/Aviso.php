<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Aviso extends Model
{
    protected $fillable = [
        'titulo',
        'conteudo',
        'ativo',
        'data_inicio',
        'data_fim',
        'criado_por',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'data_inicio' => 'datetime',
        'data_fim' => 'datetime',
    ];
}
