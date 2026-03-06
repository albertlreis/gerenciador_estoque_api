<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Evento extends Model
{
    protected $fillable = [
        'titulo',
        'tipo',
        'descricao',
        'local',
        'inicio_em',
        'fim_em',
        'criado_por',
        'google_event_id',
        'google_calendar_id',
        'google_sync_status',
        'google_last_synced_at',
    ];

    protected $casts = [
        'inicio_em' => 'datetime',
        'fim_em' => 'datetime',
        'google_last_synced_at' => 'datetime',
    ];

    public function criador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'criado_por');
    }

    public function participantes(): HasMany
    {
        return $this->hasMany(EventoParticipante::class);
    }
}
