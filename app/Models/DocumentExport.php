<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentExport extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'type', 'subject_id', 'status', 'request_payload',
        'request_method', 'path', 'filename', 'mime_type', 'error_code',
        'error_message', 'started_at', 'completed_at', 'expires_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
