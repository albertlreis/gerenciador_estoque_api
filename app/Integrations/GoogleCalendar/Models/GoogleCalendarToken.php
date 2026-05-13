<?php

namespace App\Integrations\GoogleCalendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleCalendarToken extends Model
{
    protected $table = 'google_calendar_tokens';

    protected $fillable = [
        'conexao_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'scope',
        'ultimo_refresh_em',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'ultimo_refresh_em' => 'datetime',
    ];

    public function conexao(): BelongsTo
    {
        return $this->belongsTo(GoogleCalendarConexao::class, 'conexao_id');
    }

    public function isAccessTokenExpired(): bool
    {
        return !$this->expires_at || $this->expires_at->lessThanOrEqualTo(now()->addMinutes(2));
    }
}
