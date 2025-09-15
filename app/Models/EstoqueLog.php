<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstoqueLog extends Model
{
    protected $table = 'estoque_logs';

    protected $fillable = [
        'id_usuario',
        'acao',
        'payload',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
