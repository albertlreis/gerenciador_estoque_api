<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditoriaMudanca extends Model
{
    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    protected $table = 'auditoria_mudancas';

    protected $fillable = [
        'evento_id',
        'field',
        'old_value',
        'new_value',
        'value_type',
    ];

    public function evento(): BelongsTo
    {
        return $this->belongsTo(AuditoriaEvento::class, 'evento_id');
    }
}
