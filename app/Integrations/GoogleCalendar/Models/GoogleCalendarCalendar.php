<?php

namespace App\Integrations\GoogleCalendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleCalendarCalendar extends Model
{
    protected $table = 'google_calendar_calendars';

    protected $fillable = [
        'conexao_id',
        'calendar_id',
        'summary',
        'description',
        'timezone',
        'access_role',
        'primary',
        'enabled',
        'background_color',
        'foreground_color',
        'synced_at',
        'metadata_json',
    ];

    protected $casts = [
        'primary' => 'boolean',
        'enabled' => 'boolean',
        'synced_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function conexao(): BelongsTo
    {
        return $this->belongsTo(GoogleCalendarConexao::class, 'conexao_id');
    }

    public function isWritable(): bool
    {
        return in_array($this->access_role, ['owner', 'writer'], true);
    }

    public function isWritableForMutations(): bool
    {
        return $this->enabled && $this->isWritable();
    }
}
