<?php

namespace App\Integrations\GoogleCalendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GoogleCalendarConexao extends Model
{
    protected $table = 'google_calendar_conexoes';

    protected $fillable = [
        'status',
        'email_externo',
        'nome_externo',
        'ultimo_healthcheck_em',
        'ultimo_erro',
    ];

    protected $casts = [
        'ultimo_healthcheck_em' => 'datetime',
    ];

    public function token(): HasOne
    {
        return $this->hasOne(GoogleCalendarToken::class, 'conexao_id');
    }

    public function calendars(): HasMany
    {
        return $this->hasMany(GoogleCalendarCalendar::class, 'conexao_id');
    }
}
