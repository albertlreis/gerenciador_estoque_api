<?php

namespace App\Integrations\GoogleCalendar\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleCalendarLog extends Model
{
    protected $table = 'google_calendar_logs';

    protected $fillable = [
        'conexao_id',
        'usuario_id',
        'calendar_id',
        'event_id',
        'acao',
        'status',
        'request_resumo',
        'response_resumo',
        'erro_codigo',
        'erro_mensagem',
        'executado_em',
    ];

    protected $casts = [
        'executado_em' => 'datetime',
    ];
}
