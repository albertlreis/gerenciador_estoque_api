<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceiroAuditoria extends Model
{
    protected $table = 'financeiro_auditorias';

    protected $fillable = [
        'acao','entidade_type','entidade_id','antes_json','depois_json',
        'usuario_id','ip','user_agent'
    ];

    protected $casts = [
        'antes_json' => 'array',
        'depois_json' => 'array',
    ];
}
