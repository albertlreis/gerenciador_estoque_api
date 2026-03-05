<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditoriaEvento extends Model
{
    protected $table = 'auditoria_eventos';

    protected $fillable = [
        'module',
        'action',
        'label',
        'actor_type',
        'actor_id',
        'actor_name',
        'auditable_type',
        'auditable_id',
        'route',
        'method',
        'ip',
        'user_agent',
        'origin',
        'metadata_json',
    ];

    protected $casts = [
        'metadata_json' => 'array',
    ];

    public function mudancas(): HasMany
    {
        return $this->hasMany(AuditoriaMudanca::class, 'evento_id');
    }
}
