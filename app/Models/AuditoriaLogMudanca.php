<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditoriaLogMudanca extends Model
{
    protected $table = 'auditoria_log_mudancas';

    protected $fillable = [
        'auditoria_log_id',
        'campo',
        'old_value',
        'new_value',
        'value_type',
    ];

    public function log(): BelongsTo
    {
        return $this->belongsTo(AuditoriaLog::class, 'auditoria_log_id');
    }
}
