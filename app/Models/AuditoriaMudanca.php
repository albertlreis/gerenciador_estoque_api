<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditoriaMudanca extends Model
{
    protected $table = 'auditoria_mudancas';

    protected $fillable = [
        'evento_id',
        'campo',
        'old_value',
        'new_value',
        'value_type',
    ];

    public function evento(): BelongsTo
    {
        return $this->belongsTo(AuditoriaEvento::class, 'evento_id');
    }
}
