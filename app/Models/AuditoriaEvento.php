<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditoriaEvento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'auditoria_eventos';

    protected $fillable = [
        'created_at',
        'actor_type',
        'actor_id',
        'actor_name',
        'auditable_type',
        'auditable_id',
        'module',
        'action',
        'label',
        'request_id',
        'route',
        'method',
        'ip',
        'user_agent',
        'origin',
        'metadata_json',
        'prev_hash',
        'event_hash',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function mudancas(): HasMany
    {
        return $this->hasMany(AuditoriaMudanca::class, 'evento_id');
    }
}
