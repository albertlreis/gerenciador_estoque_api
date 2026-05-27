<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditoriaLog extends Model
{
    protected $table = 'auditoria_logs';

    protected $fillable = [
        'occurred_at',
        'tipo',
        'categoria',
        'nivel',
        'modulo',
        'acao',
        'status',
        'label',
        'message',
        'actor_type',
        'actor_id',
        'actor_name',
        'entity_type',
        'entity_id',
        'source_system',
        'source_kind',
        'source_table',
        'source_id',
        'source_uid',
        'origem',
        'route',
        'method',
        'ip',
        'user_agent',
        'metadata_json',
        'context_json',
        'raw_excerpt',
        'retention_days',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'metadata_json' => 'array',
        'context_json' => 'array',
        'retention_days' => 'integer',
    ];

    public function mudancas(): HasMany
    {
        return $this->hasMany(AuditoriaLogMudanca::class, 'auditoria_log_id');
    }
}
